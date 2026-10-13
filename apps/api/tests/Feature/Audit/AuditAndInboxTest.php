<?php

declare(strict_types=1);

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Provisioning\TenantSpec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AccessFixtures;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;

/*
 * M1 step 7a — business audit and inbox (doc 25 §8, §12 T-AUD; doc 05; doc 07; D-069 mitigation 2).
 */

/** @return list<stdClass> audit rows of the tenant, oldest first */
function auditRows(array $tenant): array
{
    return AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->select(
        'select action, entity_type, entity_id, actor, before_hash, after_hash, detail::text as detail, correlation_id from audit_events order by occurred_at, id',
    ));
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->admin = member($this, $this->t);
    AccessFixtures::grant($this->t, $this->admin['id'], 'TENANT_ADMIN');
});

it('T-AUD-01: grants and revocations are audited in the same transaction, stamped with the signed actor', function () {
    $m = member($this, $this->t);
    signIn($this, $this->admin, $this->t);

    $id = spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'QCI', ['PLANT', $this->t['plants'][0]]))
        ->assertCreated()->json('data.id');
    spa($this)->postJson("/api/v1/core/role-assignments/{$id}/actions/revoke", ['reason' => 'moved'])->assertOk();

    $rows = array_values(array_filter(auditRows($this->t), fn ($r) => $r->entity_type === 'role_assignment'));
    expect(array_column($rows, 'action'))->toBe(['role_assignment.granted', 'role_assignment.revoked'])
        ->and($rows[0]->entity_id)->toBe($id)->and($rows[0]->actor)->toBe($this->admin['id'])
        ->and($rows[0]->before_hash)->toBeNull()->and($rows[0]->after_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($rows[1]->before_hash)->toMatch('/^[0-9a-f]{64}$/')->and($rows[1]->after_hash)->not->toBe($rows[1]->before_hash)
        ->and($rows[0]->correlation_id)->not->toBeNull();
    $detail = json_decode($rows[0]->detail, true);
    expect($detail['roleCode'])->toBe('QCI')->and($detail['scopeType'])->toBe('PLANT')->and($detail)->not->toHaveKey('reason')
        ->and($rows[1]->before_hash)->toBe($rows[0]->after_hash); // same projection: the granted state is the state before revocation
})->group('integration', 'audit');

it('T-AUD-01: member administration and invitation acceptance are audited', function () {
    $email = IdentityFixtures::email();
    signIn($this, $this->admin, $this->t);
    $membership = spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $email])->assertCreated()->json('data.membershipId');
    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/resend-invitation")->assertStatus(202);
    $token = mailedTokens($this, $email)[0];
    $password = 'correct horse battery staple';
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertNoContent();
    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/suspend")->assertOk();
    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/reactivate")->assertOk();

    $rows = array_values(array_filter(auditRows($this->t), fn ($r) => $r->entity_id === $membership));
    expect(array_column($rows, 'action'))->toBe(['membership.invited', 'membership.invitation_resent', 'membership.activated', 'membership.suspended', 'membership.reactivated'])
        ->and($rows[2]->actor)->not->toBe($this->admin['id']) // the invitee activated its own membership
        ->and(json_decode($rows[3]->detail, true))->toEqual(['from' => 'ACTIVE', 'to' => 'SUSPENDED'])
        ->and(implode(' ', array_column($rows, 'detail')))->not->toContain($email);
})->group('integration', 'audit');

it('T-AUD-01: provisioning writes tenant.provisioned as the system actor', function () {
    $result = app(TenantProvisioner::class)->provision(new TenantSpec('t'.strtolower(Str::random(10)), 'Audit Co', IdentityFixtures::email(), 'AC', 'PT Audit', 'P1', 'Plant 1', '06:00'));

    $rows = auditRows(['tenant' => $result->tenantId, 'actor' => TenantProvisioner::SYSTEM_ACTOR]);
    expect($rows)->toHaveCount(1)->and($rows[0]->action)->toBe('tenant.provisioned')
        ->and($rows[0]->actor)->toBe(TenantProvisioner::SYSTEM_ACTOR)
        ->and(json_decode($rows[0]->detail, true)['adminMembershipId'])->toBe($result->adminMembershipId);
})->group('integration', 'audit');

it('T-AUD-02: audit rows roll back with a failed command', function () {
    Route::middleware(['api', 'tenant'])->post('/api/v1/_test/audited/{status}', function (int $status) {
        $db = app('db')->connection();
        app(AuditRecorder::class)->record($db, 'probe.done', 'tenant', (string) $db->selectOne('select c.tenant from ctx_api.context() c')->tenant);

        return response()->json([], $status);
    });
    signIn($this, $this->admin, $this->t);

    spa($this)->postJson('/api/v1/_test/audited/422')->assertStatus(422);
    spa($this)->postJson('/api/v1/_test/audited/201')->assertCreated();

    expect(array_column(array_filter(auditRows($this->t), fn ($r) => $r->action === 'probe.done'), 'action'))->toBe(['probe.done']);
})->group('integration', 'audit');

it('T-AUD-03: audit is append-only, the actor cannot be forged and other tenants are invisible', function () {
    $b = TenantFixtures::provision(1);
    $forged = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into audit_events (tenant_id, actor, occurred_at, action, entity_type, entity_id) values (?, ?, '2000-01-01', 'probe.forged', 'tenant', ?) returning actor, occurred_at > now() - interval '1 hour' as recent",
        [$this->t['tenant'], $b['actor'], $this->t['tenant']],
    ));
    expect($forged->actor)->toBe($this->admin['id'])->and($forged->recent)->toBeTrue();

    foreach (["update audit_events set action = 'x.y'", 'delete from audit_events', 'truncate audit_events'] as $sql) {
        expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->statement($sql)))->toThrow(QueryException::class);
    }
    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->insert(
        "insert into audit_events (tenant_id, action, entity_type, entity_id) values (?, 'probe.cross', 'tenant', ?)", [$b['tenant'], $b['tenant']],
    )))->toThrow(QueryException::class, 'row-level security');
    expect(AccessFixtures::run($b, fn (EoraPostgresConnection $db) => $db->select('select 1 from audit_events')))->toBe([]);
})->group('integration', 'audit');

it('D-069: granting a sensitive role notifies the other administrators, not the grantor or non-administrators', function () {
    $otherAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $otherAdmin['id'], 'TENANT_ADMIN', ['PLANT', $this->t['plants'][1]]);
    $bystander = member($this, $this->t);
    AccessFixtures::grant($this->t, $bystander['id'], 'QCI', ['PLANT', $this->t['plants'][0]]);
    $grantee = member($this, $this->t);
    signIn($this, $this->admin, $this->t);

    // OPR carries no sensitive permission (QCI does: inventory.reversal.request_correction)
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $grantee['membership'], 'OPR', ['PLANT', $this->t['plants'][0]]))->assertCreated();
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $grantee['membership'], 'CSA'))->assertCreated();
    spa($this)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('meta.unread', 0);

    signIn($this, $otherAdmin, $this->t);
    $inbox = spa($this)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('data.0.kind', 'access.sensitive_role_granted')->assertJsonPath('data.0.severity', 'WARNING');
    expect($inbox->json('data.0.body'))->toContain('CSA')->toContain('TENANT scope');

    signIn($this, $bystander, $this->t);
    spa($this)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('data', []);
})->group('integration', 'audit');

it('keeps each inbox private and lets its owner mark notifications read', function () {
    $otherAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $otherAdmin['id'], 'TENANT_ADMIN');
    $grantee = member($this, $this->t);
    signIn($this, $this->admin, $this->t);
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $grantee['membership'], 'INV', ['PLANT', $this->t['plants'][0]]))->assertCreated();

    signIn($this, $otherAdmin, $this->t);
    $id = spa($this)->getJson('/api/v1/inbox')->assertJsonPath('meta.unread', 1)->json('data.0.id');

    // the grantor cannot read or mark the other administrator's notification
    expect(asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->update('update inbox_notifications set read_at = now() where id = ?', [$id])))->toBe(0);

    spa($this)->postJson('/api/v1/inbox/read', ['ids' => [$id]])->assertOk()->assertJsonPath('meta.updated', 1);
    spa($this)->getJson('/api/v1/inbox')->assertJsonPath('meta.unread', 0)->assertJsonPath('data.0.id', $id);
    spa($this)->postJson('/api/v1/inbox/read', ['ids' => ['not-a-uuid']])->assertStatus(422);

    // a notification can only be addressed to the member it names
    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, title) values (?, ?, ?, ?, 'probe.x', 'INFO', 'x')",
        [$this->t['tenant'], $grantee['membership'], $otherAdmin['id'], $this->t['tenant']],
    )))->toThrow(QueryException::class, 'recipient does not match');
})->group('integration', 'audit');

<?php

declare(strict_types=1);

use App\Platform\Access\Authorizer;
use App\Platform\Access\RoleTemplates;
use App\Platform\Access\Scope;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/*
 * M1 step 5 — RBAC (doc 25 §3, §12 T-RBAC/T-ESC, §13; doc 18; D-060, D-B1-16). Group B through HTTP with the real
 * session, group C directly as eora_app with a signed context. Fixtures run as eora_provisioner, never as owner.
 */

/** Signs $u in and selects $tenant. */
function signIn(TestCase $test, array $u, array $tenant): void
{
    spa($test)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($test)->postJson('/api/v1/auth/tenant', ['tenantId' => $tenant['tenant']])->assertOk();
}

/** An accepted member of $tenant with its ids. */
function member(TestCase $test, array $tenant): array
{
    $u = activeUser($test, $tenant);
    $u['id'] = AccessFixtures::userId($tenant, $u['email']);
    $u['membership'] = AccessFixtures::membershipId($tenant, $u['id']);

    return $u;
}

/** Runs $work as eora_app inside $tenant with $actor as the signed actor. */
function asApp(array $tenant, string $actor, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($tenant['tenant'], $actor), $work);
}

function roleId(array $tenant, string $code): string
{
    return (string) AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select id from roles where code = ?', [$code])->id);
}

/** @return list<string> */
function eventActions(array $tenant): array
{
    return array_column(AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->select(
        "select action from security_events where source = 'app' and action like 'access.%' order by occurred_at",
    )), 'action');
}

/** Body for POST /core/role-assignments. */
function grantBody(array $tenant, string $membership, string $role, array $scope = ['TENANT']): array
{
    return array_filter([
        'membershipId' => $membership,
        'roleId' => roleId($tenant, $role),
        'scopeType' => $scope[0],
        'companyId' => $scope[0] === 'COMPANY' ? $scope[1] : null,
        'plantId' => $scope[0] === 'PLANT' ? $scope[1] : null,
        'reason' => 'onboarding',
    ]);
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->admin = member($this, $this->t);
    AccessFixtures::grant($this->t, $this->admin['id'], 'TENANT_ADMIN');
});

it('T-RBAC-05: seeds the doc 18 §1 templates (no platform role) and TENANT_ADMIN exactly as D-B1-16', function () {
    $catalogue = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => [
        'templates' => array_column($db->select('select code from role_templates order by code'), 'code'),
        'admin' => array_column($db->select("select permission_code from role_template_permissions where template_code = 'TENANT_ADMIN' order by 1"), 'permission_code'),
        'empty' => array_column($db->select('select t.code from role_templates t where not exists (select 1 from role_template_permissions p where p.template_code = t.code) order by 1'), 'code'),
        'sensitive' => array_column($db->select('select code from permissions where sensitive order by code'), 'code'),
        'roles' => (int) $db->selectOne('select count(*) as n from roles')->n,
    ]);

    expect($catalogue['templates'])->toBe(['APR', 'AUD', 'CSA', 'DLV', 'FGW', 'INV', 'MAD', 'MAT', 'OPR', 'PPC', 'PRL', 'QCI', 'QCS', 'TENANT_ADMIN'])
        ->and($catalogue['admin'])->toBe(['core.audit.view', 'core.users_roles.create', 'core.users_roles.post', 'core.users_roles.view'])
        ->and($catalogue['empty'])->toBe(['APR', 'MAD'])
        ->and($catalogue['sensitive'])->toContain('core.users_roles.post', 'core.approval.approve', 'inventory.reversal.request_correction', 'quality.disposition_release.approve')
        ->and($catalogue['sensitive'])->not->toContain('planning.wo.view', 'core.audit.view')
        ->and($catalogue['roles'])->toBe(14);

    // idempotent: a second run adds nothing
    AccessFixtures::roles($this->t);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from role_permissions')->n))
        ->toBe(asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from role_template_permissions')->n));
})->group('integration', 'rbac');

it('returns the effective permissions of the active tenant in /auth/me (navigation hint)', function () {
    signIn($this, $this->admin, $this->t);

    spa($this)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('permissions', ['core.audit.view', 'core.users_roles.create', 'core.users_roles.post', 'core.users_roles.view']);
})->group('integration', 'rbac');

it('lists tenant roles and members with their assignments to an administrator', function () {
    $m = member($this, $this->t);
    signIn($this, $this->admin, $this->t);

    spa($this)->getJson('/api/v1/core/roles')->assertOk()->assertJsonCount(14, 'data')
        ->assertJsonPath('data.13.code', 'TENANT_ADMIN')
        ->assertJsonPath('data.13.permissions', ['core.audit.view', 'core.users_roles.create', 'core.users_roles.post', 'core.users_roles.view']);

    $members = collect(spa($this)->getJson('/api/v1/core/members')->assertOk()->json('data'))->keyBy('membershipId');
    expect($members)->toHaveCount(2)
        ->and($members[$this->admin['membership']]['assignments'][0]['roleCode'])->toBe('TENANT_ADMIN')
        ->and($members[$this->admin['membership']]['assignments'][0]['effective'])->toBeTrue()
        ->and($members[$m['membership']]['assignments'])->toBe([]);
})->group('integration', 'rbac');

it('T-RBAC-01: a member without permission gets 403 FORBIDDEN and a durable access.forbidden event', function () {
    $m = member($this, $this->t);
    signIn($this, $m, $this->t);

    spa($this)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('permissions', []);
    spa($this)->getJson('/api/v1/core/roles')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    spa($this)->getJson('/api/v1/core/members')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $this->admin['membership'], 'CSA'))
        ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertJsonMissingPath('detail');

    expect(eventActions($this->t))->toBe(['access.forbidden', 'access.forbidden', 'access.forbidden']);
})->group('integration', 'rbac');

it('grants and revokes a scoped role; the change is effective on the next request', function () {
    $m = member($this, $this->t);
    signIn($this, $this->admin, $this->t);

    $assignment = spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'PPC', ['PLANT', $this->t['plants'][0]]))
        ->assertCreated()
        ->assertJsonPath('data.roleCode', 'PPC')->assertJsonPath('data.scopeType', 'PLANT')
        ->assertJsonPath('data.plantId', $this->t['plants'][0])->assertJsonPath('data.grantedBy', $this->admin['id'])
        ->assertJsonPath('data.effective', true)
        ->json('data.id');

    signIn($this, $m, $this->t);
    expect(spa($this)->getJson('/api/v1/auth/me')->json('permissions'))->toContain('planning.wo.post', 'demand.po.view')->not->toContain('core.users_roles.view');

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/role-assignments/{$assignment}/actions/revoke", ['reason' => 'moved to another plant'])->assertOk()
        ->assertJsonPath('data.revokeReason', 'moved to another plant')->assertJsonPath('data.effective', false);
    spa($this)->postJson("/api/v1/core/role-assignments/{$assignment}/actions/revoke", ['reason' => 'again'])->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    signIn($this, $m, $this->t);
    spa($this)->getJson('/api/v1/auth/me')->assertJsonPath('permissions', []);
})->group('integration', 'rbac');

it('T-RBAC-02 / T-ESC-03: a plant-scoped administrator grants only inside its plant (SCOPE_FORBIDDEN otherwise)', function () {
    [$p1, $p2] = $this->t['plants'];
    $plantAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $plantAdmin['id'], 'TENANT_ADMIN', ['PLANT', $p1]);
    $m = member($this, $this->t);
    signIn($this, $plantAdmin, $this->t);

    foreach ([['PLANT', $p2], ['TENANT'], ['COMPANY', $this->t['company']]] as $scope) {
        spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'QCI', $scope))
            ->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    }
    $outside = AccessFixtures::grant($this->t, $m['id'], 'MAT', ['PLANT', $p2]);
    spa($this)->postJson("/api/v1/core/role-assignments/{$outside}/actions/revoke", ['reason' => 'not mine'])
        ->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');

    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'QCI', ['PLANT', $p1]))->assertCreated();
    expect(eventActions($this->t))->toBe(array_fill(0, 4, 'access.scope_forbidden'));
})->group('integration', 'rbac');

it('T-ESC-03: a company-scoped administrator covers the company and its plants, never the tenant', function () {
    $companyAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $companyAdmin['id'], 'TENANT_ADMIN', ['COMPANY', $this->t['company']]);
    $m = member($this, $this->t);
    signIn($this, $companyAdmin, $this->t);

    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'PPC', ['PLANT', $this->t['plants'][1]]))->assertCreated();
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'AUD', ['COMPANY', $this->t['company']]))->assertCreated();
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'CSA'))->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
})->group('integration', 'rbac');

it('T-ESC-01/02: nobody grants or revokes their own assignments (SoD-06), through HTTP or SQL', function () {
    $own = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from role_assignments')->id);
    signIn($this, $this->admin, $this->t);

    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $this->admin['membership'], 'CSA'))
        ->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    spa($this)->postJson("/api/v1/core/role-assignments/{$own}/actions/revoke", ['reason' => 'step down'])
        ->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    expect(eventActions($this->t))->toBe(['access.sod_violation', 'access.sod_violation']);

    // database backstop: the signed actor itself, bypassing the controller
    $csa = roleId($this->t, 'CSA');
    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->insert(
        "insert into role_assignments (tenant_id, membership_id, role_id, scope_type, granted_by, grant_reason) values (?, ?, ?, 'TENANT', ?, 'self')",
        [$this->t['tenant'], $this->admin['membership'], $csa, $this->t['actor']],
    )))->toThrow(QueryException::class, 'SoD-06');
    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->update(
        "update role_assignments set revoked_at = now(), revoked_by = ?, revoke_reason = 'self' where id = ?", [$this->admin['id'], $own],
    )))->toThrow(QueryException::class, 'SoD-06');
})->group('integration', 'rbac');

it('T-RBAC-03: revoked, expired and not-yet-valid assignments grant nothing', function () {
    $m = member($this, $this->t);
    AccessFixtures::revoke($this->t, AccessFixtures::grant($this->t, $m['id'], 'CSA'));
    AccessFixtures::grant($this->t, $m['id'], 'AUD', ['TENANT'], now()->subDays(2)->toIso8601String(), now()->subDay()->toIso8601String());
    AccessFixtures::grant($this->t, $m['id'], 'INV', ['PLANT', $this->t['plants'][0]], now()->addDay()->toIso8601String());
    signIn($this, $m, $this->t);

    spa($this)->getJson('/api/v1/auth/me')->assertJsonPath('permissions', []);
    spa($this)->getJson('/api/v1/core/members')->assertForbidden();
})->group('integration', 'rbac');

it('T-RBAC-04: ids of another tenant are unknown; a grant in one tenant means nothing in another', function () {
    $b = TenantFixtures::provision(1);
    AccessFixtures::roles($b);
    $bMember = member($this, $b);
    signIn($this, $this->admin, $this->t);
    $m = member($this, $this->t);

    spa($this)->postJson('/api/v1/core/role-assignments', ['membershipId' => $bMember['membership']] + grantBody($this->t, $m['membership'], 'PPC'))
        ->assertStatus(422)->assertJsonValidationErrors(['membershipId']);
    spa($this)->postJson('/api/v1/core/role-assignments', ['roleId' => roleId($b, 'PPC')] + grantBody($this->t, $m['membership'], 'PPC'))
        ->assertStatus(422)->assertJsonValidationErrors(['roleId']);
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'PPC', ['PLANT', $b['plants'][0]]))
        ->assertStatus(422)->assertJsonValidationErrors(['plantId']);
    $bAssignment = AccessFixtures::grant($b, $bMember['id'], 'AUD');
    spa($this)->postJson("/api/v1/core/role-assignments/{$bAssignment}/actions/revoke", ['reason' => 'probe'])->assertNotFound();

    // nothing granted in A exists for B
    spa($this)->getJson('/api/v1/core/members')->assertOk()->assertJsonMissing(['membershipId' => $bMember['membership']]);
})->group('integration', 'rbac');

it('validates grants: duplicates, missing scope ids, suspended members', function () {
    $m = member($this, $this->t);
    signIn($this, $this->admin, $this->t);
    $body = grantBody($this->t, $m['membership'], 'QCS', ['PLANT', $this->t['plants'][0]]);

    spa($this)->postJson('/api/v1/core/role-assignments', $body)->assertCreated();
    spa($this)->postJson('/api/v1/core/role-assignments', $body)->assertStatus(422)->assertJsonValidationErrors(['roleId']);
    spa($this)->postJson('/api/v1/core/role-assignments', ['scopeType' => 'COMPANY', 'companyId' => null] + $body)->assertStatus(422)->assertJsonValidationErrors(['companyId']);
    spa($this)->postJson('/api/v1/core/role-assignments', ['scopeType' => 'TENANT'] + $body)->assertStatus(422)->assertJsonValidationErrors(['plantId']);
    spa($this)->postJson('/api/v1/core/role-assignments', ['reason' => ''] + $body)->assertStatus(422)->assertJsonValidationErrors(['reason']);
    spa($this)->postJson('/api/v1/core/role-assignments/not-a-uuid/actions/revoke', ['reason' => 'probe'])->assertNotFound();

    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update memberships set status = 'SUSPENDED' where id = ?", [$m['membership']]));
    spa($this)->postJson('/api/v1/core/role-assignments', grantBody($this->t, $m['membership'], 'AUD'))->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
})->group('integration', 'rbac');

it('T-ESC-04: protected columns, catalogue and history cannot be changed by the runtime role', function (string $sql) {
    $assignment = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from role_assignments')->id);
    $other = member($this, $this->t);

    expect(fn () => asApp($this->t, $other['id'], fn (EoraPostgresConnection $db) => $db->statement(str_replace(':id', "'{$assignment}'", $sql))))
        ->toThrow(QueryException::class, 'permission denied');
})->with([
    'role' => ['update role_assignments set role_id = role_id where id = :id'],
    'scope' => ["update role_assignments set scope_type = 'TENANT' where id = :id"],
    'grantor' => ['update role_assignments set granted_by = granted_by where id = :id'],
    'validity' => ['update role_assignments set valid_until = now() where id = :id'],
    'delete' => ['delete from role_assignments where id = :id'],
    'role edit' => ["update roles set name = 'x'"],
    'role perms' => ['delete from role_permissions'],
    'catalogue' => ["insert into permissions (code, module, action, sensitive) values ('x.y.view', 'x', 'view', false)"],
    'template' => ["insert into role_template_permissions values ('AUD', 'core.users_roles.post')"],
])->group('integration', 'rbac');

it('T-ESC-04: grantor and revoker are stamped from the signed actor; a revocation is final', function () {
    $m = member($this, $this->t);
    $other = member($this, $this->t);
    $aud = roleId($this->t, 'AUD');

    $row = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into role_assignments (tenant_id, membership_id, role_id, scope_type, granted_by, grant_reason) values (?, ?, ?, 'TENANT', ?, 'forged') returning id, granted_by",
        [$this->t['tenant'], $m['membership'], $aud, $other['id']],
    ));
    expect($row->granted_by)->toBe($this->admin['id']);

    asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->update(
        "update role_assignments set revoked_at = '2000-01-01', revoked_by = ?, revoke_reason = 'done' where id = ?", [$other['id'], $row->id],
    ));
    $revoked = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select revoked_by, revoked_at > now() - interval \'1 hour\' as recent from role_assignments where id = ?', [$row->id]));
    expect($revoked->revoked_by)->toBe($this->admin['id'])->and($revoked->recent)->toBeTrue();

    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->update(
        'update role_assignments set revoked_at = null, revoked_by = null, revoke_reason = null where id = ?', [$row->id],
    )))->toThrow(QueryException::class, 'revoked once');
})->group('integration', 'rbac');

it('T-ESC-05: a write holds the grantor\'s own assignment; a concurrent revocation of it makes the write retryable', function () {
    $m = member($this, $this->t);
    $own = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from role_assignments')->id);
    signIn($this, $this->admin, $this->t);
    $body = grantBody($this->t, $m['membership'], 'AUD');

    try {
        AccessFixtures::run($this->t, function (EoraPostgresConnection $db) use ($own, $body) {
            // another administrator's revocation of the grantor's assignment is in flight (row locked, not committed)
            $db->update("update role_assignments set revoked_at = now(), revoked_by = ?, revoke_reason = 'concurrent' where id = ?", [$this->t['actor'], $own]);

            spa($this)->postJson('/api/v1/core/role-assignments', $body)->assertStatus(503)
                ->assertJsonPath('code', 'RETRYABLE_CONFLICT')->assertHeader('Retry-After', '1');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('rollback');
    }

    spa($this)->postJson('/api/v1/core/role-assignments', $body)->assertCreated();
})->group('integration', 'rbac');

it('instantiates templates only inside the tenant\'s own context', function () {
    $b = TenantFixtures::provision(1);

    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => app(RoleTemplates::class)->instantiate($db, $b['tenant'])))
        ->toThrow(QueryException::class, 'row-level security');
})->group('integration', 'rbac');

it('authorizes a permission against a plant target (entry point for module policies)', function () {
    [$p1, $p2] = $this->t['plants'];
    $m = member($this, $this->t);
    AccessFixtures::grant($this->t, $m['id'], 'PPC', ['PLANT', $p1]);
    $authorize = fn (string $permission, string $plant) => asApp($this->t, $m['id'], function (EoraPostgresConnection $db) use ($permission, $plant) {
        try {
            app(Authorizer::class)->authorize($db, $permission, Scope::plant($plant, $this->t['company']));

            return 'OK';
        } catch (DomainProblem $e) {
            return $e->errorCode;
        }
    });

    expect($authorize('planning.wo.post', $p1))->toBe('OK')
        ->and($authorize('planning.wo.post', $p2))->toBe('SCOPE_FORBIDDEN')
        ->and($authorize('inventory.period_close.post', $p1))->toBe('FORBIDDEN');
})->group('integration', 'rbac');

it('T-RBAC-02: a scoped administrator lists only assignments inside its scope (query predicate)', function () {
    [$p1, $p2] = $this->t['plants'];
    $m = member($this, $this->t);
    AccessFixtures::grant($this->t, $m['id'], 'PPC', ['PLANT', $p1]);
    AccessFixtures::grant($this->t, $m['id'], 'QCI', ['PLANT', $p2]);
    AccessFixtures::grant($this->t, $m['id'], 'AUD', ['COMPANY', $this->t['company']]);
    $plantAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $plantAdmin['id'], 'TENANT_ADMIN', ['PLANT', $p1]);
    $companyAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $companyAdmin['id'], 'TENANT_ADMIN', ['COMPANY', $this->t['company']]);
    $visible = function (array $admin) use ($m) {
        signIn($this, $admin, $this->t);
        $members = collect(spa($this)->getJson('/api/v1/core/members')->assertOk()->json('data'))->keyBy('membershipId');

        return [count($members), collect($members[$m['membership']]['assignments'])->pluck('roleCode')->sort()->values()->all(),
            collect($members[$this->admin['membership']]['assignments'])->pluck('roleCode')->all()];
    };

    // every member is listed; assignments only where the caller's scope covers them
    expect($visible($plantAdmin))->toBe([4, ['PPC'], []])
        ->and($visible($companyAdmin))->toBe([4, ['AUD', 'PPC', 'QCI'], []])
        ->and($visible($this->admin))->toBe([4, ['AUD', 'PPC', 'QCI'], ['TENANT_ADMIN']]);
})->group('integration', 'rbac');

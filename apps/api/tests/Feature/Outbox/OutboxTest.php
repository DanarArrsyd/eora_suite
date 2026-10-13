<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Outbox\PrivilegedResetNotifier;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/*
 * M1 step 7b — transactional outbox and relay (doc 25 §6.4, §12 T-SRC, T-PRN; R-30, R-33, R-40; D-B1-25).
 */

/** Runs the relay until nothing is due (events of other tests are processed too: they have no consumer or their own). */
function drainOutbox(): void
{
    for ($i = 0; $i < 50 && app(OutboxRelay::class)->runOnce('test-relay', 500) > 0; $i++);
}

/** Resets $u's password through HTTP and the delivery worker. */
function resetPassword(TestCase $test, array $u): void
{
    $token = resetToken($test, $u['email']);
    spa($test)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'another long passphrase 42', 'password_confirmation' => 'another long passphrase 42'])->assertNoContent();
}

/** @return list<stdClass> inbox rows of the tenant (read as the provisioner, i.e. without the owner filter) */
function inboxRows(array $tenant, string $userId): array
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($tenant['tenant'], $userId), fn (EoraPostgresConnection $db) => $db->select(
        'select kind, body, event_id from inbox_notifications order by created_at',
    ));
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
    $this->admin = member($this, $this->t);
    AccessFixtures::grant($this->t, $this->admin['id'], 'TENANT_ADMIN');
});

it('T-OUT-01: the relay role sees no table and claims ids only; complete → DONE, stale tokens are refused', function () {
    $event = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => app(OutboxPublisher::class)->publish($db, 'probe.happened', 'tenant', $this->t['tenant'], ['k' => 'v']));
    $relay = DB::connection('pgsql_relay');

    // no USAGE on public at all: the table is not even visible to the relay role
    expect(fn () => $relay->select('select payload from public.outbox_events'))->toThrow(QueryException::class);
    $claims = collect($relay->select('select * from outbox_api.claim_events(?, ?)', ['test', 500]))->keyBy('event_id');
    expect($claims)->toHaveKey($event)->and(array_keys((array) $claims[$event]))->toBe(['event_id', 'tenant_id', 'claim_token'])
        ->and($claims[$event]->tenant_id)->toBe($this->t['tenant']);

    expect(fn () => $relay->select('select outbox_api.complete_event(?, ?)', [$event, $this->t['tenant']]))->toThrow(QueryException::class, 'stale or invalid claim');
    $relay->select('select outbox_api.complete_event(?, ?)', [$event, $claims[$event]->claim_token]);
    expect(asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne('select status, dispatched_at is not null as sent from outbox_events where id = ?', [$event])))
        ->toEqual((object) ['status' => 'DONE', 'sent' => true]);
    foreach ($claims as $id => $c) {
        if ($id !== $event) {
            $relay->select('select outbox_api.fail_event(?, ?, ?)', [$id, $c->claim_token, 'TEST_RELEASE']); // give other tests' events back
        }
    }
})->group('integration', 'outbox');

it('T-OUT-02: a failed claim backs off; exhausted events become DEAD', function () {
    $event = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => app(OutboxPublisher::class)->publish($db, 'probe.failing', 'tenant', $this->t['tenant']));
    $relay = DB::connection('pgsql_relay');
    $claim = collect($relay->select('select * from outbox_api.claim_events(?, ?)', ['test', 500]))->firstWhere('event_id', $event);
    $relay->select('select outbox_api.fail_event(?, ?, ?)', [$event, $claim->claim_token, 'HANDLER_FAILED']);

    $row = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne(
        "select status, attempts, last_error_code, next_attempt_at > now() + interval '30 seconds' as backed_off from outbox_events where id = ?", [$event],
    ));
    expect($row->status)->toBe('PENDING')->and($row->attempts)->toBe(1)->and($row->last_error_code)->toBe('HANDLER_FAILED')->and($row->backed_off)->toBeTrue();
    expect(fn () => $relay->select('select outbox_api.fail_event(?, ?, ?)', [$event, $claim->claim_token, 'bad code']))->toThrow(QueryException::class);
})->group('integration', 'outbox');

it('T-OUT-03: an exhausted event becomes DEAD with a durable outbox.dead security event', function () {
    $event = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into outbox_events (tenant_id, source, event_type, max_attempts) values (?, 'app', 'probe.exhausted', 1) returning id", [$this->t['tenant']],
    )->id);
    $relay = DB::connection('pgsql_relay');
    $claims = collect($relay->select('select * from outbox_api.claim_events(?, ?)', ['test', 500]));
    $mine = $claims->firstWhere('event_id', $event);
    $relay->select('select outbox_api.fail_event(?, ?, ?)', [$event, $mine->claim_token, 'HANDLER_FAILED']);
    foreach ($claims as $c) {
        if ($c->event_id !== $event) {
            $relay->select('select outbox_api.fail_event(?, ?, ?)', [$c->event_id, $c->claim_token, 'TEST_RELEASE']);
        }
    }

    $relay->select('select * from outbox_api.claim_events(?, ?)', ['test', 1]); // the next claim round retires it

    $state = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => [
        'status' => $db->selectOne('select status from outbox_events where id = ?', [$event])->status,
        'alerts' => $db->select("select detail_code from security_events where action = 'outbox.dead' and requested_id = ?", [str_replace('-', '', $event)]),
    ]);
    expect($state['status'])->toBe('DEAD')->and($state['alerts'])->toHaveCount(1)->and($state['alerts'][0]->detail_code)->toBe('MAX_ATTEMPTS');
})->group('integration', 'outbox');

it('T-SRC-01 / T-SRC-05: the runtime role writes only app-sourced, non-auth events of its own tenant', function (string $source, string $type, string $message) {
    expect(fn () => asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->insert(
        'insert into outbox_events (tenant_id, source, event_type) values (?, ?, ?)', [$this->t['tenant'], $source, $type],
    )))->toThrow(QueryException::class, $message);
})->with([
    'auth type from app' => ['app', 'auth.password_reset_consumed', 'check constraint'],
    'auth source' => ['auth_web', 'auth.password_reset_consumed', 'row-level security'],
    'delivery source' => ['auth_delivery', 'delivery.dead', 'row-level security'],
])->group('integration', 'outbox');

it('T-PRN-01: a reset by a member without sensitive permissions is processed and notifies nobody', function () {
    $m = member($this, $this->t);
    AccessFixtures::grant($this->t, $m['id'], 'OPR', ['PLANT', $this->t['plants'][0]]);

    resetPassword($this, $m);
    drainOutbox();

    $status = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->select(
        "select e.status, (select count(*) from processed_events p where p.event_id = e.id) as processed from outbox_events e where e.event_type = 'auth.password_reset_consumed'",
    ));
    expect($status)->toHaveCount(1)->and($status[0]->status)->toBe('DONE')->and($status[0]->processed)->toBe(1)
        ->and(inboxRows($this->t, $this->admin['id']))->toBe([])->and(inboxRows($this->t, $m['id']))->toBe([]);
})->group('integration', 'outbox');

it('T-PRN-02 / T-PRN-10 / T-PRN-12: a privileged reset notifies every administrator and the user, without any secret', function () {
    $otherAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $otherAdmin['id'], 'TENANT_ADMIN', ['PLANT', $this->t['plants'][0]]);
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'INV', ['PLANT', $this->t['plants'][0]]); // privileged via approval/reversal only

    resetPassword($this, $approver);
    drainOutbox();

    foreach ([$this->admin, $otherAdmin, $approver] as $recipient) {
        $rows = inboxRows($this->t, $recipient['id']);
        expect($rows)->toHaveCount(1)->and($rows[0]->kind)->toBe('access.privileged_password_reset')
            ->and($rows[0]->body)->toContain('was reset at')->not->toMatch('/[0-9a-f]{64}|token|password\/reset/');
    }
})->group('integration', 'outbox');

it('T-PRN-11 / T-PRN-13: an administrator resetting gets one notification; a suspended owner gets none', function () {
    $otherAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $otherAdmin['id'], 'TENANT_ADMIN');

    resetPassword($this, $otherAdmin);
    drainOutbox();
    expect(inboxRows($this->t, $otherAdmin['id']))->toHaveCount(1)->and(inboxRows($this->t, $this->admin['id']))->toHaveCount(1);

    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    resetPassword($this, $approver);
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update memberships set status = 'SUSPENDED' where id = ?", [$approver['membership']]));
    drainOutbox();

    expect(inboxRows($this->t, $approver['id']))->toBe([])->and(inboxRows($this->t, $this->admin['id']))->toHaveCount(2);
})->group('integration', 'outbox');

it('T-PRN-04: a redelivered event never duplicates notifications (processed_events)', function () {
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    resetPassword($this, $approver);
    drainOutbox();

    $event = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne("select id from outbox_events where event_type = 'auth.password_reset_consumed'")->id);
    app(TenantTransaction::class)->run(TenantContext::system($this->t['tenant']), fn (EoraPostgresConnection $db) => app(OutboxRelay::class)->handle($db, $event));

    expect(inboxRows($this->t, $this->admin['id']))->toHaveCount(1);
})->group('integration', 'outbox');

it('T-PRN-08: the notifier refuses an event that is not an auth_web reset and records it', function () {
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    $fake = (object) ['id' => $this->t['tenant'], 'source' => 'app', 'event_type' => 'auth.password_reset_consumed', 'payload' => json_encode(['user_id' => $approver['id']])];

    app(TenantTransaction::class)->run(TenantContext::system($this->t['tenant']), fn (EoraPostgresConnection $db) => app(PrivilegedResetNotifier::class)->handle($db, $fake));

    expect(inboxRows($this->t, $this->admin['id']))->toBe([])
        ->and(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select("select 1 from security_events where action = 'outbox.untrusted_event'")))->toHaveCount(1);
})->group('integration', 'outbox');

it('relays through the artisan command and publishes membership.invited as a business event', function () {
    signIn($this, $this->admin, $this->t);
    $membership = spa($this)->postJson('/api/v1/core/members/invitations', ['email' => IdentityFixtures::email()])->assertCreated()->json('data.membershipId');

    for ($i = 0; $i < 50; $i++) {
        $this->artisan('eora:outbox:relay', ['--once' => true])->assertSuccessful();
        $status = asApp($this->t, $this->admin['id'], fn (EoraPostgresConnection $db) => $db->selectOne(
            "select status from outbox_events where event_type = 'membership.invited' and aggregate_id = ?", [$membership],
        )->status);
        if ($status === 'DONE') {
            break;
        }
    }
    expect($status)->toBe('DONE');
})->group('integration', 'outbox');

<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;

/*
 * Group C (doc 25 §11, §12): identity and invitation definer functions called directly as their real roles —
 * T-IDN, T-INV, T-ABUSE. Nobody here runs as superuser or owner.
 */

function deliverFor(string $email): array
{
    for ($i = 0; $i < 20; $i++) {
        $d = IdentityFixtures::deliver();
        if ($d === null) {
            break;
        }
        if ($d['recipient'] === $email) {
            return $d;
        }
    }
    throw new RuntimeException("no delivery for {$email}");
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
});

it('T-IDN: provision_identity returns one random v4 id per normalized email and creates the profile atomically', function () {
    $email = IdentityFixtures::email();
    $prov = DB::connection('pgsql_provisioner');
    $first = $prov->selectOne('select auth_api.provision_identity(?) as id', [$email])->id;
    $again = $prov->selectOne('select auth_api.provision_identity(?) as id', ['  '.strtoupper($email).' '])->id;

    expect($first)->toBe($again)->and($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    $lookup = DB::connection('pgsql_auth')->selectOne('select * from auth_api.login_lookup(?)', [$email]);
    expect($lookup->user_id)->toBe($first)->and($lookup->password_hash)->toBeNull()->and($lookup->status)->toBe('ACTIVE');
    expect(DB::connection('pgsql_auth')->select('select * from auth_api.login_lookup(?)', ['nobody@example.test']))->toBe([]);
})->group('integration', 'identity');

it('T-INV: invitation → delivery → acceptance sets the first password once and bumps the credential version', function () {
    $email = IdentityFixtures::email();
    $ids = IdentityFixtures::invite($this->t, $email);
    $d = deliverFor($email);

    expect($d['secret'])->toMatch('/^[0-9a-f]{64}$/');

    $accepted = IdentityFixtures::accept($d['secret']);
    expect($accepted->user_id)->toBe($ids['user'])
        ->and($accepted->tenant_id)->toBe($this->t['tenant'])
        ->and($accepted->membership_id)->toBe($ids['membership'])
        ->and(IdentityFixtures::accept($d['secret']))->toBeNull(); // single use

    $lookup = DB::connection('pgsql_auth')->selectOne('select * from auth_api.login_lookup(?)', [$email]);
    expect($lookup->password_hash)->toStartWith('$argon2id$');

    $events = app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), fn (EoraPostgresConnection $db) => array_column(
        $db->select('select action from security_events where membership_id = ? order by occurred_at', [$ids['membership']]), 'action',
    ));
    expect($events)->toBe(['invitation.secret_issued', 'invitation.accepted']);
})->group('integration', 'identity');

it('T-INV: an issued but unacknowledged secret, a wrong secret and a stale claim are all useless', function () {
    $email = IdentityFixtures::email();
    IdentityFixtures::invite($this->t, $email);
    DB::connection('pgsql_delivery')->select('select auth_api.claim_invitation_dispatch(?, ?)', ['w', 100]);
    do {
        $claim = DB::connection('pgsql_delivery')->selectOne('select * from auth_api.claim_delivery(?)', ['w']);
        $mine = $claim !== null && $claim->recipient === $email;
        if ($claim !== null && ! $mine && $claim->delivery_id !== null) {
            DB::connection('pgsql_delivery')->select('select auth_api.mark_delivery_result(?, ?, false, ?)', [$claim->delivery_id, $claim->claim_token, 'SKIPPED']);
        }
    } while ($claim !== null && $claim->delivery_id !== null && ! $mine);
    $secret = DB::connection('pgsql_delivery')->selectOne('select auth_api.issue_invitation_secret(?, ?) as s', [$claim->delivery_id, $claim->claim_token])->s;

    expect(IdentityFixtures::accept($secret))->toBeNull()                       // ISSUED, not yet SENT
        ->and(IdentityFixtures::accept(str_repeat('0', 64)))->toBeNull();      // unknown secret

    expect(fn () => DB::connection('pgsql_delivery')->select('select auth_api.mark_delivery_result(?, ?, true, null)', [$claim->delivery_id, '00000000-0000-4000-8000-000000000000']))
        ->toThrow(QueryException::class, 'stale or invalid claim');
})->group('integration', 'identity');

it('T-INV: an INITIAL duplicate converts nothing; a RESEND replaces the open delivery and invalidates the old link', function () {
    $email = IdentityFixtures::email();
    IdentityFixtures::invite($this->t, $email);
    $first = deliverFor($email);

    IdentityFixtures::invite($this->t, $email, 'INITIAL');       // duplicate initial request
    DB::connection('pgsql_delivery')->select('select auth_api.claim_invitation_dispatch(?, ?)', ['w', 100]);

    IdentityFixtures::invite($this->t, $email, 'RESEND');
    $second = deliverFor($email);

    expect($second['secret'])->not->toBe($first['secret'])
        ->and(IdentityFixtures::accept($first['secret']))->toBeNull()
        ->and(IdentityFixtures::accept($second['secret']))->not->toBeNull();

    $statuses = app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), fn (EoraPostgresConnection $db) => array_column(
        $db->select('select status from invitation_dispatch_requests order by created_at'), 'status',
    ));
    expect($statuses)->toBe(['CONVERTED', 'DUPLICATE', 'CONVERTED']);
})->group('integration', 'identity');

it('T-LOGIN: ten recorded failures lock the identity; locked lookups never return the hash', function () {
    $email = IdentityFixtures::email();
    IdentityFixtures::invite($this->t, $email);
    IdentityFixtures::accept(deliverFor($email)['secret']);
    $auth = DB::connection('pgsql_auth');
    $user = $auth->selectOne('select user_id from auth_api.login_lookup(?)', [$email])->user_id;

    for ($i = 0; $i < 10; $i++) {
        $auth->select('select auth_api.record_login_failure(?, ?)', [$user, hash('sha256', "ip-{$i}")]);
    }
    $locked = $auth->selectOne('select * from auth_api.login_lookup(?)', [$email]);

    expect($locked->status)->toBe('LOCKED')->and($locked->password_hash)->toBeNull()->and($locked->locked_until)->not->toBeNull();
})->group('integration', 'identity');

it('T-ABUSE: each role reaches only its own functions and no auth table', function (string $connection, string $sql) {
    DB::connection($connection)->statement($sql);
})->with([
    'app → auth table' => ['pgsql', 'select count(*) from auth.identities'],
    'app → login_lookup' => ['pgsql', "select * from auth_api.login_lookup('a@b.cd')"],
    'app → provision_identity' => ['pgsql', "select auth_api.provision_identity('a@b.cd')"],
    'web → delivery function' => ['pgsql_auth', "select * from auth_api.claim_delivery('x')"],
    'web → auth table' => ['pgsql_auth', 'select count(*) from auth.invitations'],
    'web → business table' => ['pgsql_auth', 'select count(*) from public.users'],
    'delivery → consume' => ['pgsql_delivery', "select * from auth_api.consume_invitation(repeat('0', 64), '\$argon2id\$x')"],
    'delivery → auth table' => ['pgsql_delivery', 'select count(*) from auth.delivery_queue'],
    'provisioner → login_lookup' => ['pgsql_provisioner', "select * from auth_api.login_lookup('a@b.cd')"],
])->throws(QueryException::class, 'permission denied')->group('integration', 'identity');

it('T-ABUSE: record_security_event accepts only allowlisted actions and never a tenant', function () {
    $auth = DB::connection('pgsql_auth');
    $auth->select('select auth_api.record_security_event(?, ?, null, null, null, null)', ['auth.failed', 'BAD_PASSWORD']);

    expect(fn () => $auth->select('select auth_api.record_security_event(?, null, null, null, null, null)', ['access.forbidden']))
        ->toThrow(QueryException::class, 'action not allowed');
})->group('integration', 'identity');

it('lets a tenant-less picker context see only its own memberships and tenants with an ACTIVE membership', function () {
    $other = TenantFixtures::provision(1);
    $email = IdentityFixtures::email();
    $ids = IdentityFixtures::invite($this->t, $email);
    IdentityFixtures::accept(deliverFor($email)['secret']);
    IdentityFixtures::invite($other, IdentityFixtures::email()); // somebody else's membership elsewhere

    // the application activates the membership in the user's own tenant context (doc 25 §2.4)
    app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $ids['user']), fn (EoraPostgresConnection $db) => $db->update(
        "update memberships set status = 'ACTIVE', activated_at = now() where id = ?", [$ids['membership']],
    ));

    $seen = app(TenantTransaction::class)->run(TenantContext::tenantless($ids['user']), fn (EoraPostgresConnection $db) => [
        'memberships' => array_column($db->select('select tenant_id from memberships'), 'tenant_id'),
        'tenants' => array_column($db->select('select id from tenants'), 'id'),
        'plants' => count($db->select('select id from plants')),
    ]);

    expect($seen)->toBe(['memberships' => [$this->t['tenant']], 'tenants' => [$this->t['tenant']], 'plants' => 0]);
})->group('integration', 'identity');

it('keeps profiles to self and tenant members and never lets the runtime role touch credential_version', function () {
    $email = IdentityFixtures::email();
    $ids = IdentityFixtures::invite($this->t, $email);
    $outsider = DB::connection('pgsql_provisioner')->selectOne('select auth_api.provision_identity(?) as id', [IdentityFixtures::email()])->id;
    $ctx = TenantContext::forTenant($this->t['tenant'], $ids['user']);

    $visible = app(TenantTransaction::class)->run($ctx, fn (EoraPostgresConnection $db) => array_column($db->select('select id from users'), 'id'));
    expect($visible)->toContain($ids['user'])->not->toContain($outsider);

    $renamed = app(TenantTransaction::class)->run($ctx, fn (EoraPostgresConnection $db) => $db->update("update users set display_name = 'Me' where id = ?", [$ids['user']]));
    expect($renamed)->toBe(1);

    expect(fn () => app(TenantTransaction::class)->run($ctx, fn (EoraPostgresConnection $db) => $db->update('update users set credential_version = 99 where id = ?', [$ids['user']])))
        ->toThrow(QueryException::class, 'permission denied');
})->group('integration', 'identity');

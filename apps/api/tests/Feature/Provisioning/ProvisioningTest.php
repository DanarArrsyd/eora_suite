<?php

declare(strict_types=1);

use App\Platform\Auth\Delivery\ConsoleInvitation;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Provisioning\TenantSpec;
use App\Platform\Security\SecurityHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccessFixtures;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;

/*
 * M1 step 6 — tenant provisioning (doc 25 §2.5, §12 T-PROV; D-B1-13, D-B1-16, D-B1-18). Runs through the real roles:
 * eora_provisioner (registry + tenant transaction), eora_auth_delivery (worker / console), eora_auth_web (acceptance).
 */

function spec(string $code, string $email, string $dayStarts = '07:00'): TenantSpec
{
    return new TenantSpec($code, 'Demo Stamping', $email, 'DS', 'PT Demo Stamping', 'P1', 'Plant 1', $dayStarts);
}

function provisionArgs(string $code, string $email, array $extra = []): array
{
    return ['code' => $code, 'name' => 'Demo Stamping', 'admin-email' => $email, '--company-code' => 'DS', '--company-name' => 'PT Demo Stamping',
        '--plant-code' => 'P1', '--plant-name' => 'Plant 1', '--day-starts' => '07:00'] + $extra;
}

/** Rows of the tenant, read as eora_provisioner inside the tenant's own context. */
function tenantState(string $tenantId): array
{
    return AccessFixtures::run(['tenant' => $tenantId, 'actor' => TenantProvisioner::SYSTEM_ACTOR], fn (EoraPostgresConnection $db) => [
        'tenant' => $db->selectOne('select code, status, timezone from tenants where id = ?', [$tenantId]),
        'companies' => (int) $db->selectOne('select count(*) as n from companies')->n,
        'plants' => (int) $db->selectOne('select count(*) as n from plants')->n,
        'calendar' => $db->selectOne('select plant_id, timezone, production_day_starts_at::text as starts from business_calendars'),
        'roles' => (int) $db->selectOne('select count(*) as n from roles')->n,
        'memberships' => $db->select('select id, status from memberships'),
        'admin' => $db->select('select ra.scope_type, ra.granted_by, r.code from role_assignments ra join roles r on r.id = ra.role_id'),
        'dispatch' => array_column($db->select('select reason from invitation_dispatch_requests order by created_at, id'), 'reason'),
    ]);
}

function tenantCode(): string
{
    return 't'.strtolower(Str::random(10));
}

it('T-PROV-01: creates tenant, company, plant, calendar, roles and an invited TENANT_ADMIN; the admin onboards end to end', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();

    $this->artisan('eora:tenant:provision', provisionArgs($code, $email))->expectsOutputToContain('Tenant created')->assertSuccessful();

    $tenantId = (string) DB::connection('pgsql_provisioner')->selectOne('select tenant_id from prov_api.provision_lookup(?, ?)', [
        $code, app(SecurityHasher::class)->hash($code."\n".$email),
    ])->tenant_id;
    $state = tenantState($tenantId);
    expect($state['tenant']->status)->toBe('ACTIVE')->and($state['tenant']->timezone)->toBe('Asia/Jakarta')
        ->and($state['companies'])->toBe(1)->and($state['plants'])->toBe(1)
        ->and($state['calendar']->plant_id)->toBeNull()->and($state['calendar']->starts)->toBe('07:00:00')
        ->and($state['roles'])->toBe(14)
        ->and($state['memberships'])->toHaveCount(1)->and($state['memberships'][0]->status)->toBe('INVITED')
        ->and($state['admin'])->toHaveCount(1)
        ->and($state['admin'][0]->code)->toBe('TENANT_ADMIN')->and($state['admin'][0]->scope_type)->toBe('TENANT')
        ->and($state['admin'][0]->granted_by)->toBe(TenantProvisioner::SYSTEM_ACTOR)
        ->and($state['dispatch'])->toBe(['INITIAL']);

    $token = mailedTokens($this, $email)[0] ?? null;
    expect($token)->not->toBeNull();
    $password = 'correct horse battery staple';
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertNoContent();
    spa($this)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk()->assertJsonPath('tenants.0.code', $code);
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $tenantId])->assertOk()
        ->assertJsonPath('permissions', ['core.audit.view', 'core.users_roles.create', 'core.users_roles.post', 'core.users_roles.view']);
    spa($this)->getJson('/api/v1/core/roles')->assertOk()->assertJsonCount(14, 'data');
})->group('integration', 'provisioning');

it('T-PROV-02: --deliver=console prints the one-time link; the e-mail worker never sends it', function () {
    $email = IdentityFixtures::email();

    $this->artisan('eora:tenant:provision', provisionArgs(tenantCode(), $email, ['--deliver' => 'console']))
        ->expectsOutputToContain('/invitations/accept#token=')->assertSuccessful();

    expect(mailedTokens($this, $email))->toBe([]);
})->group('integration', 'provisioning');

it('T-PROV-02: the console link activates the administrator; a second console run after acceptance issues nothing', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();
    $provisioner = app(TenantProvisioner::class);

    $result = $provisioner->provision(spec($code, $email), console: true);
    $link = app(ConsoleInvitation::class)->deliver($result->adminMembershipId);
    expect($link)->toMatch('/\/invitations\/accept#token=[0-9a-f]{64}$/');

    $password = 'correct horse battery staple';
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => substr((string) $link, -64), 'password' => $password, 'password_confirmation' => $password])->assertNoContent();

    $again = $provisioner->provision(spec($code, $email), console: true);
    expect($again->created)->toBeFalse()
        ->and(app(ConsoleInvitation::class)->deliver($again->adminMembershipId))->toBeNull();

    $event = AccessFixtures::run(['tenant' => $result->tenantId, 'actor' => TenantProvisioner::SYSTEM_ACTOR], fn (EoraPostgresConnection $db) => $db->select(
        "select source from security_events where action = 'invitation.delivered_console'",
    ));
    expect($event)->toHaveCount(1)->and($event[0]->source)->toBe('auth_delivery');
})->group('integration', 'provisioning');

it('T-PROV-03: rejects invalid input before touching the database', function (array $override) {
    $code = tenantCode();
    $this->artisan('eora:tenant:provision', $override + provisionArgs($code, IdentityFixtures::email()))->assertFailed();

    expect(DB::connection('pgsql_provisioner')->select('select 1 from prov_api.provision_lookup(?, ?)', [$code, str_repeat('0', 64)]))->toBe([]);
})->with([
    'code' => [['code' => 'Bad Code']],
    'email' => [['admin-email' => 'not-an-email']],
    'day start' => [['--day-starts' => '25:00']],
    'missing day start' => [['--day-starts' => '']],
    'company code' => [['--company-code' => 'lower']],
    'time zone' => [['--timezone' => 'Mars/Base']],
    'deliver' => [['--deliver' => 'pigeon']],
])->group('integration', 'provisioning');

it('T-PROV-08 / T-PROV-12: a re-run with the same inputs creates nothing and only queues a resend', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();
    $provisioner = app(TenantProvisioner::class);

    $first = $provisioner->provision(spec($code, $email));
    $second = $provisioner->provision(spec($code, $email, '09:00'));

    expect($second->tenantId)->toBe($first->tenantId)->and($second->adminMembershipId)->toBe($first->adminMembershipId)->and($second->created)->toBeFalse();
    $state = tenantState($first->tenantId);
    expect($state['companies'])->toBe(1)->and($state['plants'])->toBe(1)->and($state['memberships'])->toHaveCount(1)
        ->and($state['calendar']->starts)->toBe('07:00:00')
        ->and($state['dispatch'])->toBe(['INITIAL', 'RESEND']);
})->group('integration', 'provisioning');

it('T-PROV-10: the same code with another administrator e-mail is TENANT_CODE_TAKEN and invites nobody', function () {
    $code = tenantCode();
    $this->artisan('eora:tenant:provision', provisionArgs($code, IdentityFixtures::email()))->assertSuccessful();
    $other = IdentityFixtures::email();

    $this->artisan('eora:tenant:provision', provisionArgs($code, $other))->expectsOutputToContain('already used')->assertFailed();
    expect(mailedTokens($this, $other))->toBe([]);
})->group('integration', 'provisioning');

it('T-PROV-11 / T-PROV-16: the registry is reachable only by eora_provisioner, and only with code + key', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();
    app(TenantProvisioner::class)->provision(spec($code, $email));
    $key = app(SecurityHasher::class)->hash($code."\n".$email);

    expect(DB::connection('pgsql_provisioner')->select('select * from prov_api.provision_lookup(?, ?)', [$code, str_repeat('a', 64)]))->toBe([])
        ->and(DB::connection('pgsql_provisioner')->select('select * from prov_api.provision_lookup(?, ?)', ['unknown-code', $key]))->toBe([]);

    foreach (['pgsql', 'pgsql_auth', 'pgsql_delivery'] as $connection) {
        foreach (['select * from prov.provisioning_requests', "select * from prov_api.provision_lookup('{$code}', '{$key}')"] as $sql) {
            expect(fn () => DB::connection($connection)->select($sql))->toThrow(QueryException::class, 'permission denied');
        }
    }
    expect(fn () => DB::connection('pgsql_provisioner')->select('select * from prov.provisioning_requests'))->toThrow(QueryException::class, 'permission denied');
})->group('integration', 'provisioning');

it('T-PROV-13: a failure inside the tenant transaction rolls everything back; the retry creates the tenant once', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();
    $provisioner = app(TenantProvisioner::class);

    expect(fn () => $provisioner->provision(spec($code, $email), beforeCommit: fn () => throw new RuntimeException('crash before commit')))
        ->toThrow(RuntimeException::class, 'crash before commit');
    $key = app(SecurityHasher::class)->hash($code."\n".$email);
    $registry = DB::connection('pgsql_provisioner')->selectOne('select * from prov_api.provision_lookup(?, ?)', [$code, $key]);
    expect($registry->status)->toBe('STARTED')->and($registry->admin_membership_id)->toBeNull();
    expect(AccessFixtures::run(['tenant' => $registry->tenant_id, 'actor' => TenantProvisioner::SYSTEM_ACTOR], fn (EoraPostgresConnection $db) => $db->select('select 1 from tenants')))->toBe([]);

    $result = $provisioner->provision(spec($code, $email));
    expect($result->created)->toBeTrue()->and($result->tenantId)->toBe((string) $registry->tenant_id)
        ->and(tenantState($result->tenantId)['dispatch'])->toBe(['INITIAL']);
})->group('integration', 'provisioning');

it('T-PROV-15: lock_request after the tenant was created is ALREADY_CREATED (a racing run takes the resend path)', function () {
    $code = tenantCode();
    $email = IdentityFixtures::email();
    app(TenantProvisioner::class)->provision(spec($code, $email));
    $key = app(SecurityHasher::class)->hash($code."\n".$email);

    expect(fn () => DB::connection('pgsql_provisioner')->select('select prov_api.lock_request(?)', [$key]))->toThrow(QueryException::class, 'ALREADY_CREATED');
})->group('integration', 'provisioning');

it('T-PROV-04: provisions an administrator who already has an account elsewhere without changing their password', function () {
    $b = TenantFixtures::provision(1);
    $existing = member($this, $b);
    $code = tenantCode();

    $result = app(TenantProvisioner::class)->provision(spec($code, $existing['email']));

    spa($this)->postJson('/api/v1/auth/login', ['email' => $existing['email'], 'password' => $existing['password']])->assertOk();
    expect(tenantState($result->tenantId)['memberships'][0]->status)->toBe('INVITED');
})->group('integration', 'provisioning');

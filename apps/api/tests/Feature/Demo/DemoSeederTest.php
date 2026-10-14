<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Provisioning\TenantSpec;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;

/*
 * Demo seeder (doc 28 §1): synthetic tenant with one active user per role template, created only through the
 * production paths (provisioning, console invitation, consume_invitation, activation under RLS).
 */

it('creates one active user per role template with its default scope, every one able to sign in', function () {
    $code = demoCode();

    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('created with 14 active users')
        ->expectsOutputToContain('Password for every demo account (shown once, not stored): '.DEMO_PASSWORD)
        ->assertSuccessful();

    $state = demoState($code);
    expect($state['members'])->toHaveCount(14)
        ->and(array_unique(array_column($state['members'], 'status')))->toBe(['ACTIVE'])
        ->and(array_column($state['members'], 'role'))->toBe(['APR', 'AUD', 'CSA', 'DLV', 'FGW', 'INV', 'MAD', 'MAT', 'OPR', 'PPC', 'PRL', 'QCI', 'QCS', 'TENANT_ADMIN']);
    $byRole = array_column($state['members'], null, 'role');
    expect($byRole['QCI']->scope_type)->toBe('PLANT')->and($byRole['QCI']->plant)->toBe('P1')
        ->and($byRole['AUD']->scope_type)->toBe('TENANT')
        ->and($byRole['APR']->scope_type)->toBe('TENANT')
        ->and($byRole['TENANT_ADMIN']->email)->toBe("tenant-admin@{$code}.test");

    spa($this)->postJson('/api/v1/auth/login', ['email' => "qci@{$code}.test", 'password' => DEMO_PASSWORD])->assertOk()
        ->assertJsonPath('tenants.0.code', $code);
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $state['tenant']])->assertOk()
        ->assertJsonCount(1, 'plants')->assertJsonPath('plants.0.code', 'P1');
})->group('integration', 'demo');

/** Rows a demo run writes into the tenant (audit trail and invitation dispatches), counted as eora_provisioner. */
function demoWrites(string $tenant): int
{
    return (int) app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->selectOne(
        'select (select count(*) from audit_events) + (select count(*) from invitation_dispatch_requests) as n',
    )->n, 'pgsql_provisioner');
}

it('refuses a complete demo tenant without writing anything', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $before = demoWrites($tenant);

    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
    expect(demoWrites($tenant))->toBe($before)->and(demoState($code)['members'])->toHaveCount(14);
})->group('integration', 'demo');

it('completes an interrupted seed on the next run; a partly active tenant needs the original password', function () {
    $code = demoCode();
    // interrupted right after provisioning: tenant and an INVITED administrator, no other member yet
    app(TenantProvisioner::class)->provision(new TenantSpec($code, 'Demo Stamping', "tenant-admin@{$code}.test", 'DEMO', 'Demo', 'P1', 'Plant 1', '07:00'), console: true);

    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('completed with 14 active users')
        ->assertSuccessful();
    expect(array_unique(array_column(demoState($code)['members'], 'status')))->toBe(['ACTIVE']);
    spa($this)->postJson('/api/v1/auth/login', ['email' => "tenant-admin@{$code}.test", 'password' => DEMO_PASSWORD])->assertOk();

    // one account no longer ACTIVE, others are: finishing with a freshly generated password is refused
    $tenant = demoState($code)['tenant'];
    app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->update(
        "update memberships set status = 'INVITED' where user_id = (select id from users where email_display = ?)", ["opr@{$code}.test"],
    ), 'pgsql_provisioner');
    $this->artisan('eora:demo:seed', ['--code' => $code])
        ->expectsOutputToContain('only partly seeded')
        ->assertFailed();
})->group('integration', 'demo');

it('refuses a weak demo password and any run in production', function () {
    $this->artisan('eora:demo:seed', ['--code' => demoCode(), '--password' => 'short'])->assertExitCode(2);

    $this->app['env'] = 'production';
    $this->artisan('eora:demo:seed', ['--code' => demoCode(), '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('never runs in production')
        ->assertFailed();
})->group('integration', 'demo');

<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Demo seeder (doc 28 §1): synthetic tenant with one active user per role template, created only through the
 * production paths (provisioning, console invitation, consume_invitation, activation under RLS).
 */

const DEMO_PASSWORD = 'synthetic demo passphrase 2026';

function demoCode(): string
{
    return 'demo-'.strtolower(Str::random(8));
}

/** @return array{tenant: string, members: list<stdClass>} rows read as eora_provisioner in the demo tenant */
function demoState(string $code): array
{
    $key = app(SecurityHasher::class)->hash($code."\n"."tenant-admin@{$code}.test");
    $tenant = (string) DB::connection('pgsql_provisioner')->selectOne('select tenant_id from prov_api.provision_lookup(?, ?)', [$code, $key])->tenant_id;
    $members = app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->select(
        'select u.email_display as email, m.status, r.code as role, ra.scope_type, p.code as plant
           from memberships m join users u on u.id = m.user_id
           join role_assignments ra on ra.membership_id = m.id and ra.revoked_at is null
           join roles r on r.id = ra.role_id
           left join plants p on p.id = ra.plant_id
          order by r.code',
    ), 'pgsql_provisioner');

    return ['tenant' => $tenant, 'members' => $members];
}

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

it('never changes an existing tenant: a second run with the same code is refused', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();

    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
    expect(demoState($code)['members'])->toHaveCount(14);
})->group('integration', 'demo');

it('refuses a weak demo password and any run in production', function () {
    $this->artisan('eora:demo:seed', ['--code' => demoCode(), '--password' => 'short'])->assertExitCode(2);

    $this->app['env'] = 'production';
    $this->artisan('eora:demo:seed', ['--code' => demoCode(), '--password' => DEMO_PASSWORD])
        ->expectsOutputToContain('never runs in production')
        ->assertFailed();
})->group('integration', 'demo');

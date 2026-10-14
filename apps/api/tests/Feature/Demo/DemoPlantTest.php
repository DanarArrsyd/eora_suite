<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Str;

/*
 * Demo plant (doc 28 §3 item 3): a second plant for the plant switch in the stack E2E and local UAT, written as
 * eora_provisioner in the tenant's signed context and audited.
 */

it('adds an audited plant to the tenant, visible in the administrator\'s plant list', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];

    $this->artisan('eora:demo:plant', ['tenant' => $tenant, 'code' => 'P2', 'name' => 'Plant 2 (demo)'])
        ->expectsOutputToContain('Plant P2 added')
        ->assertSuccessful();

    $row = app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->selectOne(
        "select p.name, p.timezone, (select count(*) from audit_events a where a.action = 'plant.created' and a.entity_id = p.id) as audits
           from plants p where p.code = 'P2'",
    ), 'pgsql_provisioner');
    expect($row->name)->toBe('Plant 2 (demo)')->and($row->timezone)->toBe('Asia/Jakarta')->and((int) $row->audits)->toBe(1);

    spa($this)->postJson('/api/v1/auth/login', ['email' => "tenant-admin@{$code}.test", 'password' => DEMO_PASSWORD])->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $tenant])->assertOk()
        ->assertJsonCount(2, 'plants')->assertJsonPath('plants.1.code', 'P2');

    // a duplicate code is refused, the tenant keeps two plants
    $this->artisan('eora:demo:plant', ['tenant' => $tenant, 'code' => 'P2', 'name' => 'Again'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
})->group('integration', 'demo');

it('refuses bad input, an unknown tenant and any run in production', function () {
    $this->artisan('eora:demo:plant', ['tenant' => 'not-a-uuid', 'code' => 'P2', 'name' => 'x'])->assertExitCode(2);
    $this->artisan('eora:demo:plant', ['tenant' => (string) Str::uuid(), 'code' => 'p2', 'name' => 'x'])->assertExitCode(2);
    $this->artisan('eora:demo:plant', ['tenant' => (string) Str::uuid(), 'code' => 'P2', 'name' => 'x'])
        ->expectsOutputToContain('No tenant with this id')
        ->assertFailed();

    $this->app['env'] = 'production';
    $this->artisan('eora:demo:plant', ['tenant' => (string) Str::uuid(), 'code' => 'P2', 'name' => 'x'])
        ->expectsOutputToContain('never added in production')
        ->assertFailed();
})->group('integration', 'demo');

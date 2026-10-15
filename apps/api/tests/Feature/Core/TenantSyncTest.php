<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;

/*
 * `eora:tenants:sync` (D-072): baseline data that forced RLS keeps out of migrations reaches every provisioned tenant
 * through the provisioner in the tenant's own signed context — audited, idempotent.
 */

it('adds template permissions introduced later to every provisioned tenant, audited, and is idempotent', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $inTenant = fn (Closure $work) => app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), $work, 'pgsql_provisioner');

    // a permission added to the AUD template after the tenant was provisioned (catalogue migration stand-in)
    $owner = DB::connection('pgsql_owner');
    $owner->insert("insert into role_template_permissions (template_code, permission_code) values ('AUD', 'master.org.create')");
    try {
        $this->artisan('eora:tenants:sync')->expectsOutputToContain('role(s)')->assertSuccessful();

        $aud = $inTenant(fn (EoraPostgresConnection $db) => $db->selectOne(
            "select exists (select 1 from role_permissions rp join roles r on r.id = rp.role_id where r.code = 'AUD' and rp.permission_code = 'master.org.create') as granted,
                    (select count(*) from audit_events where action = 'role.permissions_synced' and detail->>'roleCode' = 'AUD') as audits",
        ));
        expect($aud->granted)->toBeTrue()->and((int) $aud->audits)->toBe(1);
    } finally {
        $owner->delete("delete from role_template_permissions where template_code = 'AUD' and permission_code = 'master.org.create'");
    }

    // nothing left to do: a second run writes no audit row in this tenant
    $before = $inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from audit_events')->n);
    $this->artisan('eora:tenants:sync')->assertSuccessful();
    expect($inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from audit_events')->n))->toBe($before)
        ->and($inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne("select count(*) as n from locations where location_type like 'V\\_%'")->n))->toBe(6)
        ->and(array_column($inTenant(fn (EoraPostgresConnection $db) => $db->select('select code from uoms order by code')), 'code'))->toBe(['KG', 'PCS', 'SHEET']);
})->group('integration', 'master');

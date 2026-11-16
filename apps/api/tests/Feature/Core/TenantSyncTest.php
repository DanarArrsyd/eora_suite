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

    // the stand-in left the template again: the next run takes it back (D-127); after that nothing is left to do
    $this->artisan('eora:tenants:sync')->assertSuccessful();
    $before = $inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from audit_events')->n);
    $this->artisan('eora:tenants:sync')->assertSuccessful();
    expect($inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from audit_events')->n))->toBe($before)
        ->and($inTenant(fn (EoraPostgresConnection $db) => (int) $db->selectOne("select count(*) as n from locations where location_type like 'V\\_%'")->n))->toBe(6)
        ->and(array_column($inTenant(fn (EoraPostgresConnection $db) => $db->select('select code from uoms order by code')), 'code'))->toBe(['KG', 'PCS', 'SHEET']);
})->group('integration', 'master');

it('gives the Supervisor template every permission, also one added later (D-126)', function () {
    $owner = DB::connection('pgsql_owner');
    $missing = fn () => (int) $owner->selectOne(
        "select count(*) as n from permissions p where not exists (select 1 from role_template_permissions t where t.template_code = 'SUP' and t.permission_code = p.code)",
    )->n;
    expect($missing())->toBe(0);

    $owner->insert("insert into permissions (code, module, action, sensitive) values ('zz_test.d_one_two_six.view', 'zz_test', 'view', false)");
    try {
        expect($missing())->toBe(0)
            ->and($owner->selectOne("select exists (select 1 from role_template_permissions where template_code = 'SUP' and permission_code = 'zz_test.d_one_two_six.view') as given")->given)->toBeTrue();
    } finally {
        $owner->delete("delete from role_template_permissions where permission_code = 'zz_test.d_one_two_six.view'");
        $owner->delete("delete from permissions where code = 'zz_test.d_one_two_six.view'");
    }
})->group('integration', 'master');

it('gives a provisioned tenant the Supervisor role with every permission; sync has nothing to add (D-126)', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $inTenant = fn (Closure $work) => app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), $work, 'pgsql_provisioner');
    $sup = fn () => $inTenant(fn (EoraPostgresConnection $db) => $db->selectOne(
        "select (select count(*) from role_permissions rp join roles r on r.id = rp.role_id where r.code = 'SUP') as granted,
                (select count(*) from permissions) as catalogue,
                (select count(*) from audit_events where action like 'role.%' and detail->>'roleCode' = 'SUP') as audits",
    ));

    expect((int) $sup()->granted)->toBe((int) $sup()->catalogue);
    $this->artisan('eora:tenants:sync')->assertSuccessful();
    expect((int) $sup()->audits)->toBe(0);
})->group('integration', 'master');

it('moves demand entry to delivery: DLV drafts, confirms and activates, PPC reads (D-127)', function () {
    $owner = DB::connection('pgsql_owner');
    $held = fn (string $template) => array_column($owner->select(
        "select permission_code from role_template_permissions where template_code = ? and (permission_code like 'demand.sales_order.%' or permission_code like 'demand.forecast.%' or permission_code like 'demand.po.%') order by 1",
        [$template],
    ), 'permission_code');

    expect($held('DLV'))->toBe([
        'demand.forecast.create', 'demand.forecast.post', 'demand.forecast.view',
        'demand.po.create', 'demand.po.post', 'demand.po.submit', 'demand.po.view',
        'demand.sales_order.create', 'demand.sales_order.post', 'demand.sales_order.submit', 'demand.sales_order.view',
    ])->and($held('PPC'))->toBe(['demand.forecast.view', 'demand.po.view', 'demand.sales_order.view'])
        // only the operator's provisioner may take a permission away from a role; the application never
        ->and($owner->selectOne("select has_table_privilege('eora_provisioner', 'role_permissions', 'DELETE') as p")->p)->toBeTrue()
        ->and($owner->selectOne("select has_table_privilege('eora_app', 'role_permissions', 'DELETE') as p")->p)->toBeFalse();
})->group('integration', 'master');

it('takes a permission its template gave up away from the tenant roles, audited, once (D-127)', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $inTenant = fn (Closure $work) => app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), $work, 'pgsql_provisioner');
    $aud = fn () => $inTenant(fn (EoraPostgresConnection $db) => $db->selectOne(
        "select exists (select 1 from role_permissions rp join roles r on r.id = rp.role_id where r.code = 'AUD' and rp.permission_code = 'master.org.create') as granted,
                (select count(*) from audit_events where action = 'role.permissions_revoked' and detail->>'roleCode' = 'AUD') as audits",
    ));

    $owner = DB::connection('pgsql_owner');
    $owner->insert("insert into role_template_permissions (template_code, permission_code) values ('AUD', 'master.org.create')");
    try {
        $this->artisan('eora:tenants:sync')->assertSuccessful();
        expect($aud()->granted)->toBeTrue();
    } finally {
        $owner->delete("delete from role_template_permissions where template_code = 'AUD' and permission_code = 'master.org.create'");
    }

    $this->artisan('eora:tenants:sync')->assertSuccessful();
    expect($aud()->granted)->toBeFalse()->and((int) $aud()->audits)->toBe(1);
    $this->artisan('eora:tenants:sync')->assertSuccessful();
    expect((int) $aud()->audits)->toBe(1);
})->group('integration', 'master');

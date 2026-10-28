<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Storage;

/*
 * Demo master data (doc 29 §2, M2 exit): the sample files go through the real import pipeline into a seeded demo
 * tenant — uploaded and submitted by ppc@ where the planner may create that master data (csa@ for plants' warehouses,
 * locations, work centres, machines and dies), committed by csa@. Masters are Active, routings and BOMs Draft, no map.
 */

beforeEach(function () {
    // the worker steps run in-process, after each request transaction commits
    config(['queue.default' => 'sync']);
    Storage::fake('imports');
});

function demoMasterRead(string $tenant, string $sql): stdClass
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->selectOne($sql), 'pgsql_provisioner');
}

it('loads the sample master data as the planner and the administrator, once', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];

    $this->artisan('eora:demo:master', ['--code' => $code])
        ->expectsOutputToContain('Demo master data loaded')
        ->assertSuccessful();

    $counts = demoMasterRead($tenant,
        "select (select count(*) from items where status = 'ACTIVE') as items, (select count(*) from customers) as customers,
                (select count(*) from routing_versions where status = 'DRAFT') as draft_routings, (select count(*) from routing_versions) as routings,
                (select count(*) from bom_versions where status = 'DRAFT') as draft_boms, (select count(*) from bom_versions) as boms,
                (select count(*) from bom_routing_maps) as maps,
                (select count(*) from import_jobs where status = 'COMMITTED') as jobs,
                (select string_agg(j.template, ',' order by j.template) from import_jobs j join users u on u.id = j.created_by_user and u.id = j.submitted_by
                  where u.email_display like 'ppc@%') as by_planner,
                (select count(*) from import_jobs j join users u on u.id = j.committed_by where u.email_display like 'csa@%') as by_admin");
    expect($counts)->items->toBe(8)->customers->toBe(4)->maps->toBe(0)->jobs->toBe(16)->by_planner->toBe('boms,customer_items,customers,items,material_specs,process_types,routings,suppliers,uom_conversions,uoms')->by_admin->toBe(16)
        ->and($counts->draft_routings)->toBe($counts->routings)->and($counts->routings)->toBeGreaterThan(0)
        ->and($counts->draft_boms)->toBe($counts->boms)->and($counts->boms)->toBeGreaterThan(0);

    // loading never changes existing data
    $this->artisan('eora:demo:master', ['--code' => $code])
        ->expectsOutputToContain('loaded already')
        ->assertFailed();
})->group('integration', 'demo', 'import');

it('refuses a tenant that was not seeded, a bad date and any run in production', function () {
    $this->artisan('eora:demo:master', ['--code' => demoCode()])->expectsOutputToContain('Run eora:demo:seed')->assertFailed();
    $this->artisan('eora:demo:master', ['--from' => '2026-02-30'])->assertExitCode(2);
    $this->artisan('eora:demo:master', ['--code' => 'Bad Code'])->assertExitCode(2);

    $this->app['env'] = 'production';
    $this->artisan('eora:demo:master')->expectsOutputToContain('never loads in production')->assertFailed();
})->group('integration', 'demo');

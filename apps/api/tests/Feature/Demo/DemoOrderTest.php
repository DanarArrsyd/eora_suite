<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Storage;

/*
 * Demo order (doc 33 §1.9, D-118, M4 exit): one full order through the real endpoints, each step as the demo account of
 * its role; repeated, nothing is done twice; the balances equal the ledger (BR-INV-11).
 */

beforeEach(function () {
    // the import worker steps of eora:demo:master run in-process
    config(['queue.default' => 'sync']);
    Storage::fake('imports');
});

it('runs one order from sales order to a partial shipment as the demo roles, once however often it runs', function () {
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $this->artisan('eora:demo:master', ['--code' => $code])->assertSuccessful();
    // the drafts were imported days ago: they start in the past, so the planner moves them to today first
    app(TenantTransaction::class)->run(TenantContext::system($tenant), function (EoraPostgresConnection $db): void {
        $db->update("update bom_versions set effective_from = effective_from - 3 where status = 'DRAFT'");
        $db->update("update routing_versions set effective_from = effective_from - 3 where status = 'DRAFT'");
    }, 'pgsql_provisioner');
    // sup@ imported the BOM and routing drafts (D-128): csa@ approves them, no second approver needed (D-060)
    $this->artisan('eora:demo:uat-account', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    // no stock yet: the order and its work order are made, the issue stops; with the stock, running again finishes
    $this->artisan('eora:demo:m4', ['--code' => $code])->expectsOutputToContain('BOM of BRK-A100 moved to start')->expectsOutputToContain('WO-P1-')->expectsOutputToContain('Run eora:demo:stock')->assertFailed();
    $this->artisan('eora:demo:stock', ['--code' => $code])->assertSuccessful();

    $this->artisan('eora:demo:m4', ['--code' => $code])->expectsOutputToContain('150 PCS shipped to OEM-A')->expectsOutputToContain('Demo order done')->assertSuccessful();
    $this->artisan('eora:demo:m4', ['--code' => $code])->expectsOutputToContain('Demo order done')->assertSuccessful();

    $state = demoStockRead($tenant,
        "select (select count(*) from sales_order_headers) as orders, (select count(*) from work_orders) as wos,
                (select string_agg(doc_type || ' ' || u.email_display, ', ' order by d.posted_at) from inventory_documents d join users u on u.id = d.posted_by
                  where d.idempotency_key like 'demo-m4-%') as postings,
                (select s.status || ' ' || trim_scale(coalesce((select sum(m.qty_signed) from inventory_movements m join locations l on l.id = m.location_id
                    where m.schedule_line_id = s.id and l.location_type = 'V_CUSTOMER'), 0))::text from delivery_schedule_lines s) as line,
                (select count(*) from genealogy_edges) as edges,
                (select w.status from work_orders w) as wo");
    expect($state)->orders->toBe(1)->wos->toBe(1)->edges->toBe(2)->wo->toBe('IN_PROGRESS')->line->toBe('PARTIALLY_FULFILLED 150');
    expect(str_replace("@{$code}.test", '', (string) $state->postings))
        ->toBe('MAT_ISSUE mat, PRODUCTION_POSTING prl, QC_DISPOSITION qci, FG_RECEIPT fgw, PICK fgw, SHIPMENT dlv');
    expect(app(BalanceVerification::class)->verify($tenant))->toBe([]);
})->group('integration', 'demo', 'delivery');

it('refuses a tenant that was not seeded and any run in production', function () {
    $this->artisan('eora:demo:m4', ['--code' => demoCode()])->expectsOutputToContain('Run eora:demo:seed')->assertFailed();
    $this->app['env'] = 'production';
    $this->artisan('eora:demo:m4')->expectsOutputToContain('never runs in production')->assertFailed();
})->group('integration', 'demo', 'delivery');

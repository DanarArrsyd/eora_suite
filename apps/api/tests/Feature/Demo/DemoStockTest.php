<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Storage;

/*
 * Demo opening stock (doc 32 §1, M3 exit): receipts into rack RM-A01 through the real receipt endpoint as mat@, one
 * incoming release as qci@ — Blocked and Unrestricted lots, a consumable without lots, balances equal to the ledger.
 */

beforeEach(function () {
    // the import worker steps of eora:demo:master run in-process
    config(['queue.default' => 'sync']);
    Storage::fake('imports');
});

it('posts the opening stock as the materials PIC and the inspector, once; a load cut off half-way finishes when run again', function () {
    // one seeded tenant for every scenario: each demo tenant creates 14 identities (the creation cap is platform-wide)
    $code = demoCode();
    $this->artisan('eora:demo:seed', ['--code' => $code, '--password' => DEMO_PASSWORD])->assertSuccessful();
    $tenant = demoState($code)['tenant'];
    $this->artisan('eora:demo:stock', ['--code' => $code])->expectsOutputToContain('Run eora:demo:master')->assertFailed();
    $this->artisan('eora:demo:master', ['--code' => $code])->assertSuccessful();

    // the nut supplier is inactive: the fourth receipt is refused after three were posted
    $supplier = fn (string $status) => app(TenantTransaction::class)->run(TenantContext::system($tenant),
        fn (EoraPostgresConnection $db) => $db->update("update suppliers set status = ? where code = 'SUP-NUT'", [$status]), 'pgsql_provisioner');
    $supplier('INACTIVE');
    $this->artisan('eora:demo:stock', ['--code' => $code])->expectsOutputToContain('run the command again to finish the load')->assertFailed();
    expect(demoStockRead($tenant, "select count(*) as n from inventory_documents where doc_type = 'RM_RECEIPT'")->n)->toBe(3);

    // run again: what was posted replays, the rest posts (no double stock)
    $supplier('ACTIVE');
    $this->artisan('eora:demo:stock', ['--code' => $code])
        ->expectsOutputToContain('Demo stock loaded')
        ->assertSuccessful();

    $stock = demoStockRead($tenant,
        "select (select count(*) from inventory_documents where doc_type = 'RM_RECEIPT' and status = 'POSTED') as receipts,
                (select count(*) from inventory_documents where doc_type = 'INCOMING_RELEASE') as releases,
                (select string_agg(i.code || ' ' || b.stock_status || ' ' || trim_scale(b.qty)::text, ', ' order by i.code, b.stock_status, b.qty)
                   from stock_balances b join items i on i.id = b.item_id where b.qty > 0) as lines,
                (select count(*) from inventory_documents d join users u on u.id = d.posted_by where d.doc_type = 'RM_RECEIPT' and u.email_display like 'mat@%') as by_mat,
                (select count(*) from inventory_documents d join users u on u.id = d.posted_by where d.doc_type = 'INCOMING_RELEASE' and u.email_display like 'qci@%') as by_qci");
    expect($stock)->receipts->toBe(5)->releases->toBe(1)->by_mat->toBe(5)->by_qci->toBe(1)
        ->lines->toBe('COIL-SPCC-12 BLOCKED 2500, COIL-SPCC-12 UNRESTRICTED 1800, NUT-M8 UNRESTRICTED 5000, OIL-PRESS UNRESTRICTED 200, SHEET-SPCC-16 BLOCKED 400');
    expect(app(BalanceVerification::class)->verify($tenant))->toBe([]);

    // loading never posts twice
    $this->artisan('eora:demo:stock', ['--code' => $code])->expectsOutputToContain('loads once')->assertFailed();
})->group('integration', 'demo', 'inventory');

it('refuses a tenant that was not seeded and any run in production', function () {
    $this->artisan('eora:demo:stock', ['--code' => demoCode()])->expectsOutputToContain('Run eora:demo:seed')->assertFailed();
    $this->artisan('eora:demo:stock', ['--code' => 'Bad Code'])->assertExitCode(2);
    $this->app['env'] = 'production';
    $this->artisan('eora:demo:stock')->expectsOutputToContain('never loads in production')->assertFailed();
})->group('integration', 'demo', 'inventory');

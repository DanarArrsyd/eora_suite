<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Modules\Inventory\Application\MaterialOwnership;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

it('keeps customer receipt identities, daily numbering, replay and split ownership distinct from the supplier', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = rcFixture($t);
    $owner = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("insert into customers(tenant_id,code,name) values(?,'DPM','Owner DPM') returning id", [$t['tenant']])->id);
    orgUser($this, $t, 'MAT');
    spa($this)->getJson('/api/v1/query/core.customers')->assertOk();
    $receipt = rcReceive($this, $f, ['ownerCustomerId' => $owner], 'owner-receipt-001')->assertCreated()->json('data');
    $day = (new DateTimeImmutable($f['today']))->format('dmy');
    expect($receipt['lotNo'])->toBe("RM-DPM-{$day}-0001");
    rcReceive($this, $f, ['ownerCustomerId' => $owner], 'owner-receipt-001')->assertOk()->assertJsonPath('data.lotId', $receipt['lotId']);
    rcReceive($this, $f, [], 'owner-receipt-001')->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    rcReceive($this, $f, ['itemId' => $f['oil'], 'ownerCustomerId' => $owner, 'supplierLotNo' => null], 'owner-no-lot-001')->assertUnprocessable()->assertJsonValidationErrors('ownerCustomerId');
    spa($this)->getJson('/api/v1/query/inventory.receipts?filter[id]='.$receipt['documentId'])->assertOk()
        ->assertJsonPath('data.0.ownerCode', 'DPM')->assertJsonPath('data.0.supplierCode', 'SUP-1');
    $stock = spa($this)->getJson('/api/v1/query/inventory.stock?filter[ownerCustomerId]='.$owner)->assertOk()->json('data');
    expect($stock)->toHaveCount(1)->and($stock[0]['ownership'])->toBe('CUSTOMER');
    // Same displayed namespace across items: a second item never starts another 0001 series.
    $other = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("insert into items(tenant_id,code,name,item_type,base_uom_id,lot_controlled) select tenant_id,'OTHER-RM','Other RM','RAW',base_uom_id,true from items where id=? returning id", [$f['coil']])->id);
    rcReceive($this, $f, ['itemId' => $other, 'ownerCustomerId' => $owner], 'owner-receipt-002')->assertCreated()->assertJsonPath('data.lotNo', "RM-DPM-{$day}-0002");
    orgUser($this, $t, 'SUP');
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update("update customers set status='INACTIVE' where id=?", [$owner]));
    $split = spa($this)->postJson('/api/v1/inventory/lot-splits', ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'lotId' => $receipt['lotId'],
        'locationId' => $f['rm1'], 'stockStatus' => 'BLOCKED', 'children' => [['qty' => '2']]], ['Idempotency-Key' => 'owner-split-001'])->assertCreated()->json('data');
    $owners = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->select('select owner_customer_id from lots where parent_lot_id=?', [$receipt['lotId']]));
    expect($owners)->toHaveCount(1)->and($owners[0]->owner_customer_id)->toBe($owner);
    expect(app(BalanceVerification::class)->verify($t['tenant']))->toBe([]);
    expect(fn () => AccessFixtures::run($t, fn (EoraPostgresConnection $db) => MaterialOwnership::requireAggregatePlanning($db, $f['plant'])))
        ->toThrow(DomainProblem::class);
})->group('integration', 'inventory');

it('enforces the WO customer, rejects mixed production and preserves the owner through QC and FG receipt', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = prFixture($this, $t);
    AccessFixtures::grant($t, $f['prl']['id'], 'PPC');
    AccessFixtures::grant($t, $f['mat']['id'], 'QCI');
    AccessFixtures::grant($t, $f['mat']['id'], 'FGW');
    $use = function (array $user) use ($t): void {
        spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();
        signIn($this, $user, $t);
    };
    $mps = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $wo = woCreate($this, $f, ['productionCustomerId' => $f['customer']])->assertCreated()->json('data');
    $wo = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');
    $use($f['mat']);
    AccessFixtures::grant($t, $f['mat']['id'], 'CSA');
    $wo = woAct($this, $wo['id'], 'release', $wo['version'])->assertOk()->json('data');
    $body = ['plantId' => $f['plant'], 'itemId' => $f['coil']['id'], 'locationId' => $f['rack'], 'supplierId' => $f['supplier'], 'ownerCustomerId' => $f['customer'], 'qty' => '10'];
    $rm = spa($this)->postJson('/api/v1/inventory/receipts', $body, ['Idempotency-Key' => 'owner-prod-receipt'])->assertCreated()->json('data');
    $line = ['itemId' => $f['coil']['id'], 'lotId' => $rm['lotId'], 'fromLocationId' => $f['rack'], 'qty' => '10'];
    // The original unpegged WO has no customer and must not acquire restricted material.
    // ... and the refusal is told in the user's words, not the database's (D-136)
    mfIssue($this, $f, ['lines' => [$line]], 'owner-wrong-order')->assertUnprocessable()
        ->assertJsonPath('code', 'POSTING_REFUSED')
        ->assertJsonPath('detail', 'This lot belongs to another customer. Use company material or material of this work order\'s customer.');
    mfIssue($this, $f, ['workOrderId' => $wo['id'], 'lines' => [$line]], 'owner-right-order')->assertCreated();
    mfIssue($this, $f, ['workOrderId' => $wo['id'], 'lines' => [['itemId' => $f['nut']['id'], 'fromLocationId' => $f['rack'], 'qty' => '4']]], 'owner-company-nuts')->assertCreated();
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update items set inspection_required=true where id=?', [$f['item']['id']]));
    $use($f['prl']);
    spa($this)->getJson("/api/v1/planning/mps-versions/{$mps['id']}")->assertOk()->assertJsonPath('data.id', $mps['id']);
    spa($this)->postJson("/api/v1/planning/mps-versions/{$mps['id']}/actions/recalculate", [], ['If-Match' => '"'.$mps['version'].'"'])->assertConflict()->assertJsonPath('code', 'OWNER_PEGGING_REQUIRED');
    $run = prOpen($this, $f, ['workOrderId' => $wo['id']])->assertCreated()->json('data');
    $consumption = ['itemId' => $f['coil']['id'], 'lotId' => $rm['lotId'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '5', 'evidence' => 'Scale'];
    $post = ['runId' => $run['id'], 'goodQty' => '10', 'consumptions' => [$consumption, ['itemId' => $f['nut']['id'], 'method' => 'COUNTED_REMAINING', 'qty' => '3', 'evidence' => 'Counted']]];
    spa($this)->postJson('/api/v1/production/postings', $post, ['Idempotency-Key' => 'owner-mixed-output'])->assertUnprocessable()->assertJsonValidationErrors('consumptions');
    $post['consumptions'] = [$consumption];
    // Inactivation prevents new owner receipts, but must not erase or freeze already issued material lineage.
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update("update customers set status='INACTIVE' where id=?", [$f['customer']]));
    $fg = spa($this)->postJson('/api/v1/production/postings', $post, ['Idempotency-Key' => 'owner-good-output'])->assertCreated()->json('data');
    expect($fg['lotNo'])->toStartWith('FG-OEM-W-');
    spa($this)->postJson('/api/v1/production/postings', $post, ['Idempotency-Key' => 'owner-good-output'])->assertOk()->assertJsonPath('data.lotId', $fg['lotId']);
    $use($f['mat']);
    $pending = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from stock_balances where lot_id=? and stock_status='PENDING_QC'", [$fg['lotId']])->id);
    qcDispose($this, $pending, [['outcome' => 'RELEASE', 'qty' => '10']], 'owner-qc-release')->assertCreated();
    [$released,$rack] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f, $fg) {
        $released = (string) $db->selectOne("select id from stock_balances where lot_id=? and stock_status='QC_RELEASED'", [$fg['lotId']])->id;
        $rack = (string) $db->selectOne("insert into locations(tenant_id,plant_id,warehouse_id,code,name,location_type) select tenant_id,plant_id,warehouse_id,'FG-OWN','FG owner rack','FG_WAREHOUSE' from locations where id=? returning id", [$f['rack']])->id;

        return [$released, $rack];
    });
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '10']], ['locationId' => $rack], 'owner-fg-receipt')->assertCreated();
    $use($f['prl']);
    $returned = mfAsk($this, $f, ['workOrderId' => $wo['id'], 'lotId' => $rm['lotId'], 'qty' => '2'], 'owner-return-request')->assertCreated()->json('data.documentId');
    $use($f['mat']);
    spa($this)->postJson("/api/v1/inventory/material-returns/{$returned}/receive", ['toLocationId' => $f['rack2'], 'qty' => '2'], ['Idempotency-Key' => 'owner-return-receive'])->assertCreated();
    $fgBalance = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from stock_balances where lot_id=? and stock_status='UNRESTRICTED' and qty>0", [$fg['lotId']])->id);
    [$otherCustomer,$otherPart] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $customer = (string) $db->selectOne("insert into customers(tenant_id,code,name) values(?,'SJM','Other customer') returning id", [$t['tenant']])->id;
        $part = (string) $db->selectOne("insert into customer_items(tenant_id,customer_id,item_id,customer_part_no,effective_from) values(?,?,?,'SJM-1','2026-01-01') returning id", [$t['tenant'], $customer, $f['item']['id']])->id;
        $db->insert("insert into locations(tenant_id,plant_id,warehouse_id,code,name,location_type) select tenant_id,plant_id,warehouse_id,'STG-OWN','Staging','STAGING' from locations where id=?", [$f['rack']]);

        return [$customer, $part];
    });
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update("update customers set status='ACTIVE' where id=?", [$f['customer']]));
    $dlv = rtSwitch($this, $t, 'DLV');
    $schedules = [];
    foreach ([[$f['customer'], $f['part']], [$otherCustomer, $otherPart]] as [$customer,$part]) {
        $order = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $customer])->assertCreated()->json('data');
        $order = spa($this)->putJson("/api/v1/demand/sales-orders/{$order['id']}/lines", ['lines' => [[
            'customerItemId' => $part, 'customerPoNo' => 'OWNER-PO', 'customerPoLine' => '10',
            'schedules' => [['requirementDate' => $f['today'], 'qty' => '10']],
        ]]], ['If-Match' => '"'.$order['version'].'"'])->assertOk()->json('data');
        $order = spa($this)->postJson("/api/v1/demand/sales-orders/{$order['id']}/actions/confirm", [], ['If-Match' => '"'.$order['version'].'"'])->assertOk()->json('data');
        $schedules[] = $order['lines'][0]['schedules'][0]['id'];
    }
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$schedules[1]}/proposal")->assertOk()->assertJsonCount(0, 'data.lines');
    spa($this)->postJson('/api/v1/delivery/allocations', ['scheduleLineId' => $schedules[1], 'lines' => [['stockLineId' => $fgBalance, 'qty' => '1']]], ['Idempotency-Key' => 'owner-wrong-allocate'])->assertUnprocessable()->assertJsonValidationErrors('lines.0.stockLineId');
    // Bypass the API and allocation service as the real runtime role: the database refuses the same mismatch.
    expect(fn () => app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $dlv['id']), fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_allocations(tenant_id,balance_id,demand_type,demand_id,qty) values(?,?,'SCHEDULE_LINE',?,1)", [$t['tenant'], $fgBalance, $schedules[1]],
    )))->toThrow(QueryException::class, 'only allocate to their owner customer');
    $allocation = spa($this)->postJson('/api/v1/delivery/allocations', ['scheduleLineId' => $schedules[0], 'lines' => [['stockLineId' => $fgBalance, 'qty' => '10']]], ['Idempotency-Key' => 'owner-right-allocate'])->assertCreated()->json('data.allocationIds.0');
    $use($f['mat']);
    spa($this)->postJson('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => [['allocationId' => $allocation, 'qty' => '10']]], ['Idempotency-Key' => 'owner-pick'])->assertCreated();
    $staged = spa($this)->getJson("/api/v1/delivery/staged?plantId={$f['plant']}&customerId={$f['customer']}")->assertOk()->json('data.0.stockLineId');
    shShip($this, $f, [['scheduleLineId' => $schedules[0], 'stockLineId' => $staged, 'qty' => '10']], ['vehicleNo' => 'B 1234 AA', 'driverName' => 'Test driver'], 'owner-shipment')->assertCreated();
    expect(AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select owner_customer_id from lots where id=?', [$fg['lotId']])->owner_customer_id))->toBe($f['customer']);
    expect(app(BalanceVerification::class)->verify($t['tenant']))->toBe([]);
    expect(fn () => AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update work_orders set production_customer_id=null where id=?', [$wo['id']])))
        ->toThrow(QueryException::class, 'keeps its production customer');
})->group('integration', 'inventory');

it('refuses relabelled customer stock through direct SQL, isolates tenants and preserves ownership on reversal', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = rcFixture($t);
    $owner = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("insert into customers(tenant_id,code,name) values(?,'DPM','DPM') returning id", [$t['tenant']])->id);
    orgUser($this, $t, 'MAT');
    $receipt = rcReceive($this, $f, ['ownerCustomerId' => $owner], 'owner-sql-receipt')->assertCreated()->json('data');
    expect(rcRun($t, fn (EoraPostgresConnection $db) => $db->selectOne('select current_user as actor')->actor))->toBe('eora_app');
    expect(fn () => rcRun($t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_balances(tenant_id,plant_id,location_id,item_id,lot_id,stock_status,ownership,uom_id,qty)
         select tenant_id,plant_id,?,item_id,lot_id,stock_status,'OWN',uom_id,0 from stock_balances where lot_id=?",
        [$f['rm2'], $receipt['lotId']],
    )))->toThrow(QueryException::class, 'ledger ownership must match the lot owner');
    $other = TenantFixtures::provision();
    $foreign = AccessFixtures::run($other, fn (EoraPostgresConnection $db) => (string) $db->selectOne("insert into customers(tenant_id,code,name) values(?,'FOREIGN','Foreign owner') returning id", [$other['tenant']])->id);
    rcReceive($this, $f, ['ownerCustomerId' => $foreign], 'owner-foreign-receipt')->assertUnprocessable()->assertJsonValidationErrors('ownerCustomerId');
    $reversal = rvRequest($this, $receipt['documentId'], 'owner-reverse-request')->assertCreated()->json('data.documentId');
    rtSwitch($this, $t, 'INV');
    rvApprove($this, $reversal, 'owner-reverse-approve')->assertCreated();
    expect(trStock($this, $receipt['lotId']))->toBe([]);
    expect(rcRun($t, fn (EoraPostgresConnection $db) => $db->selectOne('select owner_customer_id from lots where id=?', [$receipt['lotId']])->owner_customer_id))->toBe($owner);
    expect(app(BalanceVerification::class)->verify($t['tenant']))->toBe([]);
})->group('integration', 'inventory');

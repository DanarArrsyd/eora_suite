<?php

declare(strict_types=1);

use App\Modules\Planning\Recovery\MaterialCapacity;
use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/* D-141 / doc 39: Daily Recovery keeps material per owner (D-1, D-3); allocation rules are unit-tested in RecoveryAllocationTest. */

it('plans a customer part from its own material first and tops it up with company material only when the customer allows it', function () {
    $t = TenantFixtures::provision(2);
    AccessFixtures::roles($t);
    $f = woFixture($this, $t);
    $ppc = rtSwitch($this, $t, 'PPC');
    AccessFixtures::grant($t, $ppc['id'], 'DLV');
    AccessFixtures::grant($t, $ppc['id'], 'MAT');
    $target = rtDayFrom($f['today'], 1);
    [$other, $otherPart, $rm, $supplier] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $db->update('update items set lot_controlled = true where id in (?, ?)', [$f['coil']['id'], $f['nut']['id']]);
        $customer = (string) $db->selectOne("insert into customers(tenant_id,code,name) values(?,'SJM','Customer SJM') returning id", [$t['tenant']])->id;
        $part = (string) $db->selectOne("insert into customer_items(tenant_id,customer_id,item_id,customer_part_no,effective_from) values(?,?,?,'SJM-100','2026-01-01') returning id",
            [$t['tenant'], $customer, $f['item']['id']])->id;
        $warehouse = $db->selectOne("insert into warehouses(tenant_id,plant_id,code,name,warehouse_type) values (?,?,'OWN-RM','Owner RM','RAW_MATERIAL') returning id", [$t['tenant'], $f['plant']])->id;
        $location = (string) $db->selectOne("insert into locations(tenant_id,plant_id,warehouse_id,code,name,location_type) values (?,?,?,'OWN-RM','Owner RM','RM_WAREHOUSE') returning id", [$t['tenant'], $f['plant'], $warehouse])->id;
        $supplier = (string) $db->selectOne("insert into suppliers(tenant_id,code,name) values (?,'OWN-SUP','Owner supplier') returning id", [$t['tenant']])->id;

        return [$customer, $part, $location, $supplier];
    });
    foreach ([[$f['customer'], $f['part']], [$other, $otherPart]] as $i => [$customer, $part]) {
        $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $customer])->assertCreated()->json('data');
        $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
            'customerItemId' => $part, 'customerPoNo' => 'OWNER-PLAN-'.$i, 'customerPoLine' => '10',
            'schedules' => [['requirementDate' => $target, 'qty' => '100']],
        ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
        spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    }
    // OEM-W supplies 20 kg of its own coil; the company holds 30 kg of coil and plenty of nuts
    $receive = fn (string $item, string $qty, array $extra, string $key) => spa($this)->postJson('/api/v1/inventory/receipts', [
        'plantId' => $f['plant'], 'itemId' => $item, 'locationId' => $rm, 'supplierId' => $supplier, 'qty' => $qty] + $extra, ['Idempotency-Key' => $key])->assertCreated();
    $receive($f['coil']['id'], '20', ['ownerCustomerId' => $f['customer'], 'supplierLotNo' => 'OEM-COIL'], 'owner-plan-coil-oem');
    $receive($f['coil']['id'], '30', ['supplierLotNo' => 'KMI-COIL'], 'owner-plan-coil-company');
    $receive($f['nut']['id'], '1000', ['supplierLotNo' => 'KMI-NUT'], 'owner-plan-nuts');
    $read = fn () => collect(spa($this)->getJson('/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $f['plant'], 'targetDate' => $target]))
        ->assertOk()->json('data'))->keyBy('customerCode');

    // one posting = one owner (D-135): OEM-W's coil needs OEM-W's nuts too, and company coil may not top it up (D-3 off)
    $rows = $read();
    expect($rows['OEM-W']['materialCapacityQty'])->toBe('0')->and($rows['SJM']['materialCapacityQty'])->toBe('60');
    // with its own nuts, OEM-W makes 40 pcs from its own 20 kg only; SJM makes 60 from the company's 30 kg, never from OEM-W's
    $receive($f['nut']['id'], '100', ['ownerCustomerId' => $f['customer'], 'supplierLotNo' => 'OEM-NUT'], 'owner-plan-nuts-oem');
    $rows = $read();
    expect($rows['OEM-W']['materialCapacityQty'])->toBe('40')->and($rows['SJM']['materialCapacityQty'])->toBe('60');
    expect(collect($rows['SJM']['lots'])->pluck('owner_code')->filter()->all())->toBe([]);

    // a plan saved now is flagged for review once the top-up rule changes its material capacity
    $saved = spa($this)->putJson('/api/v1/planning/daily-recovery', [
        'plantId' => $f['plant'], 'targetDate' => $target, 'customerId' => $f['customer'], 'itemId' => $f['item']['id'],
        'minimumQty' => 0, 'batchQty' => 1, 'bufferQty' => 0, 'shift1Qty' => 40, 'shift2Qty' => 0, 'shift3Qty' => 0,
        'reason' => 'Make what OEM-W material allows',
    ], ['If-Match' => '"0"'])->assertOk()->json('data');
    expect($saved['inputsChanged'])->toBeFalse();
    // D-3 on (through the customer master, kept by the Supervisor, D-128): a company run tops up OEM-W's own run
    rtSwitch($this, $t, 'SUP');
    $customer = spa($this)->getJson('/api/v1/query/core.customers?filter[id]='.$f['customer'])->assertOk()->json('data.0');
    expect($customer['companyMaterialTopUp'])->toBeFalse();
    spa($this)->patchJson('/api/v1/core/customers/'.$f['customer'], ['companyMaterialTopUp' => true], ['If-Match' => '"'.$customer['version'].'"'])
        ->assertOk()->assertJsonPath('data.companyMaterialTopUp', true);
    mpsAs($this, $t, $ppc);
    $rows = $read();
    expect($rows['OEM-W']['materialCapacityQty'])->toBe('100')->and($rows['SJM']['materialCapacityQty'])->toBe('60');
    expect($rows['OEM-W']['inputsChanged'])->toBeTrue();
})->group('integration', 'planning');

it('reserves what a released work order still needs as one-owner runs, never a mixed one', function () {
    $capacity = app(MaterialCapacity::class);
    // customer C owns coil but no nuts: its WO cannot mix C's coil with company nuts, so the whole run is company material
    $pool = ['customer-c|coil' => '10', 'COMPANY|coil' => '10', 'COMPANY|nut' => '20'];
    $capacity->reserveRun($pool, 'customer-c', ['coil' => '10', 'nut' => '10']);
    $plain = fn (array $pool) => array_map(static fn (string $q) => (float) $q, $pool);
    expect($plain($pool))->toBe(['customer-c|coil' => 10.0, 'COMPANY|coil' => 0.0, 'COMPANY|nut' => 10.0]);
    // with half of the nuts of its own, half of the run is C's own and half the company's
    $pool = ['customer-c|coil' => '10', 'customer-c|nut' => '5', 'COMPANY|coil' => '10', 'COMPANY|nut' => '20'];
    $capacity->reserveRun($pool, 'customer-c', ['coil' => '10', 'nut' => '10']);
    expect($plain($pool))->toBe(['customer-c|coil' => 5.0, 'customer-c|nut' => 0.0, 'COMPANY|coil' => 5.0, 'COMPANY|nut' => 15.0]);
    // short of its own nuts and no company run can make the rest (C supplies coil, no top-up): C's coil stays committed
    $pool = ['customer-c|coil' => '100', 'customer-c|nut' => '10', 'COMPANY|nut' => '50'];
    $supplied = new ReflectionProperty($capacity, 'supplied');
    $supplied->setValue($capacity, ['customer-c' => ['coil' => true]]);
    $capacity->reserveRun($pool, 'customer-c', ['coil' => '100', 'nut' => '100']);
    expect($plain($pool))->toBe(['customer-c|coil' => 0.0, 'customer-c|nut' => 0.0, 'COMPANY|nut' => 50.0]);
});

it('keeps a short draft\'s customer material committed instead of freeing it for later plans', function () {
    $t = TenantFixtures::provision(2);
    AccessFixtures::roles($t);
    $f = woFixture($this, $t);
    AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f) {
        $capacity = app(MaterialCapacity::class);
        $coil = $f['coil']['id'];
        $nut = $f['nut']['id'];
        // the draft needs 100 pcs = 50 kg coil + 200 nuts; customer C holds 50 kg of coil and only 20 nuts, no top-up allowed
        $pool = ["customer-c|{$coil}" => '50', "customer-c|{$nut}" => '20'];
        expect($capacity->consume($db, $f['plant'], $f['item']['id'], '100', $f['today'], $pool, [], true, 'customer-c'))->toBeFalse();
        // all of C's coil stays committed to the draft, none is left for a later plan of the same customer
        expect((float) $pool["customer-c|{$coil}"])->toBe(0.0)->and((float) $pool["customer-c|{$nut}"])->toBe(0.0);
    });
})->group('integration', 'planning');

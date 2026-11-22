<?php

declare(strict_types=1);

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
        $db->update('update items set lot_controlled = true where id = ?', [$f['coil']['id']]);
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
    $receive($f['nut']['id'], '1000', [], 'owner-plan-nuts');
    $read = fn () => collect(spa($this)->getJson('/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $f['plant'], 'targetDate' => $target]))
        ->assertOk()->json('data'))->keyBy('customerCode');

    // D-3 off: OEM-W makes 40 pcs from its own 20 kg only; SJM makes 60 from the company's 30 kg, never from OEM-W's coil
    $rows = $read();
    expect($rows['OEM-W']['materialCapacityQty'])->toBe('40')->and($rows['SJM']['materialCapacityQty'])->toBe('60');
    expect(collect($rows['SJM']['lots'])->pluck('owner_code')->filter()->all())->toBe([]);

    // D-3 on (through the customer master, kept by the Supervisor, D-128): company coil tops up OEM-W's own
    rtSwitch($this, $t, 'SUP');
    $customer = spa($this)->getJson('/api/v1/query/core.customers?filter[id]='.$f['customer'])->assertOk()->json('data.0');
    expect($customer['companyMaterialTopUp'])->toBeFalse();
    spa($this)->patchJson('/api/v1/core/customers/'.$f['customer'], ['companyMaterialTopUp' => true], ['If-Match' => '"'.$customer['version'].'"'])
        ->assertOk()->assertJsonPath('data.companyMaterialTopUp', true);
    mpsAs($this, $t, $ppc);
    $rows = $read();
    expect($rows['OEM-W']['materialCapacityQty'])->toBe('100')->and($rows['SJM']['materialCapacityQty'])->toBe('60');
})->group('integration', 'planning');

<?php

declare(strict_types=1);

use App\Modules\Planning\Recovery\MaterialCapacity;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

it('nets real Before Check through QC and FG receipt, persists editable plans and creates one retriable draft WO', function () {
    $t = TenantFixtures::provision(2);
    AccessFixtures::roles($t);
    $f = qcFixture($this, $t);
    AccessFixtures::grant($t, $f['qci']['id'], 'DLV');
    AccessFixtures::grant($t, $f['qci']['id'], 'FGW');
    $target = rtDayFrom($f['today'], 1);
    $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $f['part'], 'customerPoNo' => 'RECOVERY-PO', 'customerPoLine' => '10',
        'schedules' => [['requirementDate' => $target, 'qty' => '90']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    $ppc = rtSwitch($this, $t, 'PPC');
    $read = fn () => spa($this)->getJson('/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $f['plant'], 'targetDate' => $target]));
    $first = $read()->assertOk()->json('data.0');
    expect($first)->toMatchArray(['customerCode' => 'OEM-W', 'requiredQty' => '90', 'beforeCheckQty' => '90', 'fgQty' => '0', 'netQty' => '0', 'proposedQty' => '0', 'status' => 'WAIT_QC']);
    expect($first['lots'][0]['lot_no'])->not->toBeNull();
    $mps = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    expect($mps['plannedQty'])->toBe('0')->and(array_column($mps['items'][0]['supplies'], 'kind'))->toContain('PENDING_QC');

    mpsAs($this, $t, $f['qci']);
    qcDispose($this, $f['pending'], [['outcome' => 'RELEASE', 'qty' => '70'], ['outcome' => 'REJECT', 'qty' => '20', 'reasonCode' => 'CRACK']], 'recovery-qc-first')->assertCreated();
    mpsAs($this, $t, $ppc);
    $afterQc = $read()->assertOk()->json('data.0');
    expect($afterQc)->toMatchArray(['beforeCheckQty' => '0', 'acceptedQty' => '70', 'fgQty' => '0', 'netQty' => '20']);
    $fg = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f, $t) {
        $wh = $db->selectOne("select id from warehouses where plant_id = ? and code = 'RM'", [$f['plant']])->id;

        return $db->selectOne("insert into locations(tenant_id,plant_id,warehouse_id,code,name,location_type)
            values(?,?,?,'REC-FG','Recovery FG','FG_WAREHOUSE') returning id", [$t['tenant'], $f['plant'], $wh])->id;
    });
    $acceptedLine = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from stock_balances where item_id = ? and stock_status = 'QC_RELEASED' and qty > 0", [$f['item']['id']])->id);
    mpsAs($this, $t, $f['qci']);
    spa($this)->postJson('/api/v1/warehouse/fg-receipts', ['plantId' => $f['plant'], 'locationId' => $fg, 'lines' => [['stockLineId' => $acceptedLine, 'qty' => '70']]], ['Idempotency-Key' => 'recovery-fg-receive'])->assertCreated();
    mpsAs($this, $t, $ppc);
    $afterFg = $read()->assertOk()->json('data.0');
    expect($afterFg)->toMatchArray(['acceptedQty' => '0', 'fgQty' => '70', 'netQty' => '20']);
    $body = ['plantId' => $f['plant'], 'targetDate' => $target, 'customerId' => $f['customer'], 'itemId' => $f['item']['id'], 'planId' => null,
        'minimumQty' => 500, 'batchQty' => 22, 'bufferQty' => 0, 'shift1Qty' => 506, 'shift2Qty' => 0, 'shift3Qty' => 0,
        'reason' => 'Recover rejected output with one full batch'];
    $save = spa($this)->putJson('/api/v1/planning/daily-recovery', $body, ['If-Match' => '"0"'])->assertOk()->json('data');
    expect($save)->toMatchArray(['netQty' => '20', 'proposedQty' => '506', 'planQty' => '506', 'inputsChanged' => false]);
    $read()->assertOk()->assertJsonPath('data.0.inputsChanged', false);
    spa($this)->putJson('/api/v1/planning/daily-recovery', $body, ['If-Match' => '"0"'])->assertStatus(412);
    $createUrl = "/api/v1/planning/daily-recovery/{$save['planId']}/actions/create-work-order";
    $wo = spa($this)->postJson($createUrl, [], ['If-Match' => '"'.$save['version'].'"'])->assertCreated()->json('data');
    spa($this)->postJson($createUrl, [], ['If-Match' => '"'.$save['version'].'"'])->assertOk()->assertJsonPath('data.workOrderId', $wo['workOrderId'])->assertJsonPath('data.replayed', true);
    spa($this)->getJson('/api/v1/planning/work-orders/'.$wo['workOrderId'])->assertOk()->assertJsonPath('data.qty', '506')->assertJsonPath('data.status', 'DRAFT');
    // A recovery WO made before D-135 from several deliveries has no customer at all: it stays updatable as a
    // company-material order instead of being frozen by the planning-customer guard (Codex on #95).
    app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $ppc['id']), function (EoraPostgresConnection $db) use ($wo, $f) {
        $line = $db->selectOne('select schedule_line_id from work_orders where id = ?', [$wo['workOrderId']])->schedule_line_id;
        $db->update('update work_orders set production_customer_id = null, schedule_line_id = null where id = ?', [$wo['workOrderId']]);
        expect($db->update("update work_orders set note = 'Legacy recovery order' where id = ?", [$wo['workOrderId']]))->toBe(1);
        $db->update('update work_orders set production_customer_id = ?, schedule_line_id = ? where id = ?', [$f['customer'], $line, $wo['workOrderId']]);
    });
    $covered = $read()->assertOk()->json('data.0');
    expect($covered['netQty'])->toBe('0')->and($covered['workOrderId'])->toBe($wo['workOrderId'])
        ->and($covered['planQty'])->toBe('506')->and($covered['positionQty'])->toBe('416');

    // A subsequent plan keeps the original row immutable and records a new revision.
    $body['planId'] = $covered['planId'];
    $body['shift1Qty'] = 0;
    $body['reason'] = 'Review an additional requirement separately';
    $second = spa($this)->putJson('/api/v1/planning/daily-recovery', $body, ['If-Match' => '"'.$covered['version'].'"'])->assertOk()->json('data');
    expect($second['revision'])->toBe(2)->and($second['planId'])->not->toBe($covered['planId']);
    app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $ppc['id']), function (EoraPostgresConnection $db) use ($covered) {
        expect(fn () => $db->update("update daily_recovery_plans set reason = 'Rewrite old history' where id = ?", [$covered['planId']]))->toThrow(QueryException::class);
    });
})->group('integration', 'planning');

it('BR-PLN-REC-01 allocates shared Before Check before customer filtering and rejects changed sources and unauthorized edits', function () {
    $t = TenantFixtures::provision(2);
    AccessFixtures::roles($t);
    $f = qcFixture($this, $t);
    AccessFixtures::grant($t, $f['qci']['id'], 'DLV');
    $target = rtDayFrom($f['today'], 1);
    $other = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $id = $db->selectOne("insert into customers(tenant_id,code,name) values (?,'ZZZ','Second customer') returning id", [$t['tenant']])->id;
        $part = $db->selectOne("insert into customer_items(tenant_id,customer_id,item_id,customer_part_no,effective_from) values (?,?,?,'SECOND-PART','2026-01-01') returning id", [$t['tenant'], $id, $f['item']['id']])->id;

        return ['customer' => $id, 'part' => $part];
    });
    foreach ([['customer' => $f['customer'], 'part' => $f['part']], $other] as $index => $mapping) {
        $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $mapping['customer']])->assertCreated()->json('data');
        $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
            'customerItemId' => $mapping['part'], 'customerPoNo' => 'REC-SHARED-'.$index, 'customerPoLine' => '10',
            'schedules' => [['requirementDate' => $target, 'qty' => '90']],
        ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
        spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    }
    $ppc = rtSwitch($this, $t, 'PPC');
    $url = '/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $f['plant'], 'targetDate' => $target]);
    $rows = spa($this)->getJson($url)->assertOk()->json('data');
    expect($rows[0]['allocated']['beforeCheck'])->toBe('90')->and($rows[1]['allocated']['beforeCheck'])->toBe('0')->and($rows[1]['netQty'])->toBe('90');
    spa($this)->getJson($url.'&customerId='.$other['customer'])->assertOk()->assertJsonPath('data.0.allocated.beforeCheck', '0')->assertJsonPath('data.0.netQty', '90');
    $body = ['plantId' => $f['plant'], 'targetDate' => $target, 'customerId' => $other['customer'], 'itemId' => $f['item']['id'], 'planId' => null,
        'minimumQty' => 500, 'batchQty' => 22, 'bufferQty' => 0, 'shift1Qty' => 506, 'shift2Qty' => 0, 'shift3Qty' => 0, 'reason' => 'Recovery shared coverage'];
    $plan = spa($this)->putJson('/api/v1/planning/daily-recovery', $body, ['If-Match' => '"0"'])->assertOk()->json('data');
    mpsAs($this, $t, $f['qci']);
    qcDispose($this, $f['pending'], [['outcome' => 'REJECT', 'qty' => '10', 'reasonCode' => 'CRACK']], 'recovery-source-change')->assertCreated();
    mpsAs($this, $t, $ppc);
    spa($this)->postJson("/api/v1/planning/daily-recovery/{$plan['planId']}/actions/create-work-order", [], ['If-Match' => '"'.$plan['version'].'"'])->assertStatus(409)->assertJsonPath('code', 'RECOVERY_INPUTS_CHANGED');
    mpsAs($this, $t, $f['qci']);
    spa($this)->putJson('/api/v1/planning/daily-recovery', $body, ['If-Match' => '"0"'])->assertForbidden();
    expect(fn () => app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $f['qci']['id']),
        fn (EoraPostgresConnection $db) => $db->update("update daily_recovery_plans set reason = 'Unauthorized direct SQL' where id = ?", [$plan['planId']])
    ))->toThrow(QueryException::class, 'changed by a planner');
    AccessFixtures::grant($t, $f['qci']['id'], 'PPC', ['PLANT', $f['plant']]);
    spa($this)->getJson($url)->assertOk();
    $outside = collect($t['plants'])->first(fn (string $id) => $id !== $f['plant']);
    spa($this)->getJson('/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $outside, 'targetDate' => $target]))->assertForbidden();
    $tenant2 = TenantFixtures::provision(1);
    expect(AccessFixtures::run($tenant2, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from daily_recovery_plans where id = ?', [$plan['planId']])->n))->toBe(0);

})->group('integration', 'planning');

it('keeps the material demand of a Daily Recovery draft WO in MRP after its finished output covers the MPS', function () {
    $t = TenantFixtures::provision(2);
    AccessFixtures::roles($t);
    $f = woFixture($this, $t);
    $ppc = rtSwitch($this, $t, 'PPC');
    AccessFixtures::grant($t, $ppc['id'], 'DLV');
    $target = rtDayFrom($f['today'], 1);
    $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $f['part'], 'customerPoNo' => 'REC-MRP-RM', 'customerPoLine' => '10',
        'schedules' => [['requirementDate' => $target, 'qty' => '100']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    $plan = spa($this)->putJson('/api/v1/planning/daily-recovery', [
        'plantId' => $f['plant'], 'targetDate' => $target, 'customerId' => $f['customer'], 'itemId' => $f['item']['id'],
        'minimumQty' => 0, 'batchQty' => 1, 'bufferQty' => 0, 'shift1Qty' => 100, 'shift2Qty' => 0, 'shift3Qty' => 0,
        'reason' => 'Plan one hundred pieces with material procurement',
    ], ['If-Match' => '"0"'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/planning/daily-recovery/{$plan['planId']}/actions/create-work-order", [], ['If-Match' => '"'.$plan['version'].'"'])->assertCreated();
    $mps = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    expect($mps['plannedQty'])->toBe('0');
    $mps = mpsAct($this, $mps, 'submit')->assertOk()->json('data');
    rtSwitch($this, $t, 'CSA');
    $mps = mpsAct($this, $mps, 'approve')->assertOk()->json('data');
    mpsAs($this, $t, $ppc);
    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    foreach (['COIL-9' => '50', 'NUT-9' => '200'] as $code => $quantity) {
        $material = collect($run['items'])->firstWhere('itemCode', $code);
        expect($material['orders'])->toHaveCount(1)->and($material['orders'][0]['qty'])->toBe($quantity);
    }
    // Receiving enough RM for 200 does not make all 200 available to new plans: the existing draft needs 100.
    $receipt = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $warehouse = $db->selectOne("insert into warehouses(tenant_id,plant_id,code,name,warehouse_type) values (?,?,'REC-RM','Recovery RM','RAW_MATERIAL') returning id", [$t['tenant'], $f['plant']])->id;
        $location = $db->selectOne("insert into locations(tenant_id,plant_id,warehouse_id,code,name,location_type) values (?,?,?,'REC-RM','Recovery RM','RM_WAREHOUSE') returning id", [$t['tenant'], $f['plant'], $warehouse])->id;
        $supplier = $db->selectOne("insert into suppliers(tenant_id,code,name) values (?,'REC-SUP','Recovery supplier') returning id", [$t['tenant']])->id;

        return ['locationId' => $location, 'supplierId' => $supplier];
    });
    AccessFixtures::grant($t, $ppc['id'], 'MAT');
    foreach (['coil' => '100', 'nut' => '400'] as $component => $quantity) {
        spa($this)->postJson('/api/v1/inventory/receipts', ['plantId' => $f['plant'], 'itemId' => $f[$component]['id'], 'qty' => $quantity] + $receipt,
            ['Idempotency-Key' => 'recovery-draft-rm-'.$component])->assertCreated();
    }
    spa($this)->getJson('/api/v1/planning/daily-recovery?'.http_build_query(['plantId' => $f['plant'], 'targetDate' => $target]))
        ->assertOk()->assertJsonPath('data.0.materialCapacityQty', '100');
    // Even a shortage of the first component must keep the draft's other components committed.
    AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f) {
        $coil = MaterialCapacity::COMPANY.'|'.$f['coil']['id'];
        $nut = MaterialCapacity::COMPANY.'|'.$f['nut']['id'];
        $pool = [$coil => '100', $nut => '400'];
        expect(app(MaterialCapacity::class)->consume($db, $f['plant'], $f['item']['id'], '300', $f['today'], $pool, [], true))->toBeFalse();
        expect(bccomp($pool[$coil], '0', 6))->toBe(0)->and(bccomp($pool[$nut], '0', 6))->toBe(0);
    });
})->group('integration', 'planning');

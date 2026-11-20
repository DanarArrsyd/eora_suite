<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * Generate SPK (doc 38 §12, D-139): the Daily Recovery shortfall + safety stock becomes a work order with reason SPK
 * released by the generating planner, and every released finished WO due by the date is spread over the line's
 * machines (preferred, then alternative) with the rest proposed as overtime.
 */

it('generates the WO the shortfall needs, spreads operations over machines, proposes overtime and repeats nothing', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    $target = rtDayFrom($f['today'], 5); // the fixture's released WO of 100 is due that day
    AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        // the weld line: a quarter hour per shift, a second welder as the alternative, overtime limit 0.2 h per machine and day
        $db->update('update work_centers set available_hours_per_shift = 0.25 where id = ?', [$f['wc2']['id']]);
        $db->insert("insert into machines (tenant_id, plant_id, work_center_id, code, name) values (?, ?, ?, 'W-2', 'Welder 2')", [$t['tenant'], $f['plant'], $f['wc2']['id']]);
        $db->update('update plants set spk_ot_limit_hours = 0.2 where id = ?', [$f['plant']]);
    });
    rtSwitch($this, $t, 'DLV');
    $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $f['part'], 'customerPoNo' => 'GEN-PO', 'customerPoLine' => '10', 'schedules' => [['requirementDate' => $target, 'qty' => '120']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    $ppc = rtSwitch($this, $t, 'PPC');
    spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $f['item']['id'], 'safetyStock' => '40'])->assertCreated();

    spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => '2026-01-01'])->assertStatus(422);
    $gen = spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => $target])->assertOk()->json('data');

    // need 120 − the released WO's 100 + safety stock 40 = 60; the material left after that WO allows 50 (nuts: 100 left, 2 per piece)
    expect($gen['materialShort'])->toHaveCount(1)
        ->and($gen['materialShort'][0])->toMatchArray(['itemCode' => 'BRK-100', 'needQty' => '60', 'plannedQty' => '50', 'shortQty' => '10'])
        ->and($gen['workOrders'])->toHaveCount(1)
        ->and($gen['workOrders'][0])->toMatchArray(['customerCode' => 'OEM-W', 'qty' => '50']);
    $made = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select w.status, w.reason_code, w.submitted_by, w.released_by, w.routing_version_id is not null as snapshot, g.generated_by
           from work_orders w join spk_generated_orders g on g.work_order_id = w.id where w.id = ?', [$gen['workOrders'][0]['id']]));
    // released by the generating planner alone (G-2), with the usual snapshot
    expect($made)->toMatchArray(['status' => 'RELEASED', 'reason_code' => 'SPK', 'submitted_by' => $ppc['id'], 'released_by' => $ppc['id'], 'snapshot' => true, 'generated_by' => $ppc['id']]);

    // weld 300 pcs/h with 5 min QC: 50 pcs fill a 0.25 h shift. WO 1: 50 on W-1, 50 on the alternative W-2; WO 2 (50): nothing left,
    // so it stays on W-1 as 0.25 h overtime, over the 0.2 h limit; the press line has no hours per shift and takes both WOs
    expect(collect($gen['spks'])->pluck('linesAdded', 'workCenterCode')->all())->toBe(['PRS-01' => 2, 'WLD-01' => 3])
        ->and($gen['overtime'])->toHaveCount(1)
        ->and($gen['overtime'][0])->toMatchArray(['machineCode' => 'W-1', 'workCenterCode' => 'WLD-01', 'hours' => '0.25', 'qty' => '50',
            'workOrderCode' => $gen['workOrders'][0]['code'], 'overLimit' => true, 'limitHours' => '0.2']);
    $weld = collect($gen['spks'])->firstWhere('workCenterCode', 'WLD-01');
    $lines = spa($this)->getJson("/api/v1/production/spk/{$weld['id']}")->assertOk()->json('data.lines');
    expect(array_map(fn ($l) => [$l['workOrderCode'], $l['machineCode'], $l['plannedQty'], $l['hours']], $lines))->toBe([
        [$f['wo']['code'], 'W-1', '50', '0.25'],
        [$f['wo']['code'], 'W-2', '50', '0.25'],
        [$gen['workOrders'][0]['code'], 'W-1', '50', '0.25'],
    ]);
    $press = spa($this)->getJson('/api/v1/production/spk/'.collect($gen['spks'])->firstWhere('workCenterCode', 'PRS-01')['id'])->assertOk()->json('data');
    // one setup per item and operation on a machine (G-4): the second WO's blanking needs no new die change
    expect(array_column($press['lines'], 'setupMinutes'))->toBe(['30', '0']);

    // generating again plans nothing twice: the new WO is supply now and every operation is planned
    $again = spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => $target])->assertOk()->json('data');
    expect($again['workOrders'])->toBe([])->and($again['spks'])->toBe([]);

    // a generated WO that no SPK plans any more is cancelled; while one SPK still plans it, it stays
    $keep = fn (array $spk, string $wo) => array_values(array_map(fn ($l) => ['workOrderId' => $l['workOrderId'], 'routingOperationId' => $l['routingOperationId'],
        'machineId' => $l['machineId'], 'shiftId' => $l['shiftId'], 'plannedQty' => $l['plannedQty'], 'priority' => $l['priority'],
        'setupMinutes' => (float) $l['setupMinutes'], 'qcSampleMinutes' => (float) $l['qcSampleMinutes']],
        array_filter($spk['lines'], fn ($l) => $l['workOrderId'] !== $wo)));
    $weldSpk = spa($this)->getJson("/api/v1/production/spk/{$weld['id']}")->json('data');
    spa($this)->putJson("/api/v1/production/spk/{$weld['id']}", ['lines' => $keep($weldSpk, $gen['workOrders'][0]['id'])], ['If-Match' => '"'.$weldSpk['version'].'"'])->assertOk();
    $status = fn () => AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select status from work_orders where id = ?', [$gen['workOrders'][0]['id']])->status);
    expect($status())->toBe('RELEASED');
    spa($this)->putJson("/api/v1/production/spk/{$press['id']}", ['lines' => $keep($press, $gen['workOrders'][0]['id'])], ['If-Match' => '"'.$press['version'].'"'])->assertOk();
    expect($status())->toBe('CANCELLED');
})->group('integration', 'production');

it('makes several WOs in one run from a shared material pool and keeps an operation after what its predecessor already has (Codex on #98)', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    $target = rtDayFrom($f['today'], 5);
    [$s2, $vPart, $vCustomer] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $calendar = (string) $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, ?, 'DAY2', 'Two shifts', '2026-01-01') returning id",
            [$t['tenant'], $f['plant']])->id;
        $db->insert("insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, 'S1', 'Shift 1', '07:00', '15:00')", [$t['tenant'], $calendar]);
        $s2 = (string) $db->selectOne("insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, 'S2', 'Shift 2', '15:00', '23:00') returning id", [$t['tenant'], $calendar])->id;
        $customer = (string) $db->selectOne("insert into customers (tenant_id, code, name) values (?, 'OEM-V', 'Customer V') returning id", [$t['tenant']])->id;
        $part = (string) $db->selectOne("insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) values (?, ?, ?, 'V-100', '2026-01-01') returning id",
            [$t['tenant'], $customer, $f['item']['id']])->id;

        return [$s2, $part, $customer];
    });
    rtSwitch($this, $t, 'DLV');
    foreach ([[$f['customer'], $f['part'], '120'], [$vCustomer, $vPart, '100']] as [$customer, $part, $qty]) {
        $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $customer])->assertCreated()->json('data');
        $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
            'customerItemId' => $part, 'customerPoNo' => 'GEN-'.$qty, 'customerPoLine' => '10', 'schedules' => [['requirementDate' => $target, 'qty' => $qty]],
        ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
        spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    }
    rtSwitch($this, $t, 'PPC');
    spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $f['item']['id'], 'safetyStock' => '40'])->assertCreated();
    // the planner already put the released WO's blanking (op 10) in shift 2 of the press line
    $op10 = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 10', [$f['wo']['id']])->id);
    $pressDraft = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc']['id'], 'productionDate' => $target])->assertCreated()->json('data');
    spa($this)->putJson("/api/v1/production/spk/{$pressDraft['id']}", ['lines' => [spkLine($f, ['routingOperationId' => $op10, 'machineId' => $f['m1']['id'], 'plannedQty' => '100', 'shiftId' => $s2])]],
        ['If-Match' => '"'.$pressDraft['version'].'"'])->assertOk();

    // the press is down: the new WOs' blanking cannot be placed, so their welding is not planned either (G-3)
    $press = fn (string $status) => AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update machines set status = ? where id = ?', [$status, $f['m1']['id']]));
    $press('INACTIVE');
    $gen = spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => $target])->assertOk()->json('data');
    // OEM-V first (code order on the same day): 100 + safety stock 40 − the released WO's 100 = 40; that leaves nuts for 10 more pieces,
    // so OEM-W's 120 (safety stock counted once per item) gets a WO of 10 and 110 short — two WOs in one run
    expect(array_column($gen['workOrders'], 'qty', 'customerCode'))->toBe(['OEM-V' => '40', 'OEM-W' => '10'])
        ->and($gen['materialShort'])->toHaveCount(1)
        ->and($gen['materialShort'][0])->toMatchArray(['customerCode' => 'OEM-W', 'needQty' => '120', 'plannedQty' => '10', 'shortQty' => '110']);
    // the released WO's welding (op 20) comes after its blanking: shift 2, never shift 1
    $weld = collect($gen['spks'])->firstWhere('workCenterCode', 'WLD-01');
    $lines = collect(spa($this)->getJson("/api/v1/production/spk/{$weld['id']}")->assertOk()->json('data.lines'))->where('workOrderId', $f['wo']['id']);
    expect($lines->pluck('shiftCode')->unique()->values()->all())->toBe(['S2']);
    $newWos = collect($gen['workOrders'])->pluck('code')->sort()->values()->all();
    expect(collect(spa($this)->getJson("/api/v1/production/spk/{$weld['id']}")->json('data.lines'))->whereIn('workOrderCode', $newWos))->toHaveCount(0)
        ->and(collect($gen['skipped'])->where('opSeq', 20)->pluck('workOrderCode')->sort()->values()->all())->toBe($newWos)
        ->and(collect($gen['skipped'])->where('opSeq', 10)->pluck('reason')->unique()->values()->all())->toBe(['No active machine on PRS-01.']);

    // with the press back, its SPK released and under revision: Generate adds to the draft revision once, and never again (Codex on #98)
    $press('ACTIVE');
    $pressSpk = spa($this)->getJson("/api/v1/production/spk/{$pressDraft['id']}")->json('data');
    $pressSpk = spa($this)->postJson("/api/v1/production/spk/{$pressSpk['id']}/actions/release", [], ['If-Match' => '"'.$pressSpk['version'].'"'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/production/spk/{$pressSpk['id']}/actions/revise", ['reason' => 'Press back in service'], ['If-Match' => '"'.$pressSpk['version'].'"'])->assertCreated();
    $more = spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => $target])->assertOk()->json('data');
    expect($more['workOrders'])->toBe([])->and(collect($more['spks'])->pluck('workCenterCode')->sort()->values()->all())->toBe(['PRS-01', 'WLD-01']);
    $again = spa($this)->postJson('/api/v1/production/spk/generate', ['plantId' => $f['plant'], 'productionDate' => $target])->assertOk()->json('data');
    expect($again['spks'])->toBe([])->and($again['workOrders'])->toBe([]);
})->group('integration', 'production');

it('keeps reason SPK to Generate SPK and its release to the generating planner, also through direct SQL', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    // the Supervisor sets the plant's SPK overtime limit on the plant (D-139); empty clears it
    rtSwitch($this, $t, 'SUP');
    $plantRow = spa($this)->getJson('/api/v1/query/core.plants?filter[id]='.$f['plant'])->assertOk()->json('data.0');
    $plantRow = spa($this)->patchJson("/api/v1/core/plants/{$f['plant']}", ['name' => $plantRow['name'], 'spkOtLimitHours' => 2.5], ['If-Match' => '"'.$plantRow['version'].'"'])
        ->assertOk()->assertJsonPath('data.spkOtLimitHours', '2.5')->json('data');
    spa($this)->patchJson("/api/v1/core/plants/{$f['plant']}", ['name' => $plantRow['name'], 'spkOtLimitHours' => 30], ['If-Match' => '"'.$plantRow['version'].'"'])
        ->assertStatus(422)->assertJsonValidationErrors(['spkOtLimitHours']);
    spa($this)->patchJson("/api/v1/core/plants/{$f['plant']}", ['name' => $plantRow['name'], 'spkOtLimitHours' => null], ['If-Match' => '"'.$plantRow['version'].'"'])
        ->assertOk()->assertJsonPath('data.spkOtLimitHours', null);
    $prl = orgUser($this, $t, 'PRL');
    rtSwitch($this, $t, 'PPC');
    $draft = fn (string $reason) => fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into work_orders (tenant_id, plant_id, wo_no, item_id, uom_id, qty, due_date, reason_code, note)
         values (?, ?, 'WO-X-2026-9001', ?, (select base_uom_id from items where id = ?), 10, ?, ?, 'Direct SQL test') returning id",
        [$t['tenant'], $f['plant'], $f['item']['id'], $f['item']['id'], $f['today'], $reason])->id;

    // a WO with reason SPK never exists without its generated record
    expect(fn () => asApp($t, $f['ppc']['id'], $draft('SPK')))->toThrow(PDOException::class, 'made by Generate SPK');
    // the record is the generating planner's: production cannot write one
    expect(fn () => asApp($t, $prl['id'], function (EoraPostgresConnection $db) use ($draft, $t, $f) {
        $wo = $draft('SPK')($db);
        $db->insert('insert into spk_generated_orders (tenant_id, plant_id, work_order_id, production_date, generated_by) values (?, ?, ?, ?, (select c.actor from ctx_api.context() c))',
            [$t['tenant'], $f['plant'], $wo, $f['today']]);
    }))->toThrow(QueryException::class);
    // and an ordinary WO never takes reason SPK by an edit
    $other = asApp($t, $f['ppc']['id'], $draft('OTHER'));
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update("update work_orders set reason_code = 'SPK' where id = ?", [$other])))
        ->toThrow(QueryException::class, 'keeps its reason');
    // nor does its requester release it alone: the second-person rule stands for every other WO
    expect(fn () => asApp($t, $f['ppc']['id'], function (EoraPostgresConnection $db) use ($other) {
        $db->update("update work_orders set status = 'SUBMITTED', submitted_by = (select c.actor from ctx_api.context() c), submitted_at = now() where id = ?", [$other]);
        $db->update("update work_orders set status = 'RELEASED', released_by = (select c.actor from ctx_api.context() c), released_at = now() where id = ?", [$other]);
    }))->toThrow(QueryException::class, 'second person');
})->group('integration', 'production');

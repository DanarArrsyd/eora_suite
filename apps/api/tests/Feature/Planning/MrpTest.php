<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M5 step 4 (doc 34 §1.4, D-124, D-059, BR-PLN-06): MRP from the approved MPS through the API. BRK-100's BOM is coil
 * 0.5 KG + nut 2 PCS per piece; the MPS of mpsFixture plans 270 pieces due (and started) on Monday of week B.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/** Calculates, submits and (as a new CSA) approves the plant's MPS; returns [mps, csa]. Ends signed in as the planner. */
function mrpApprovedMps(object $test, array $t, array $f): array
{
    $m = spa($test)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $m = mpsAct($test, $m, 'submit')->assertOk()->json('data');
    $csa = rtSwitch($test, $t, 'CSA');
    $m = mpsAct($test, $m, 'approve')->assertOk()->json('data');
    mpsAs($test, $t, $f['ppc']);

    return [$m, $csa];
}

/** @return array<string, mixed> the run's item of $code */
function mrpItem(array $run, string $code): array
{
    return collect($run['items'])->firstWhere('itemCode', $code) ?? throw new LogicException("{$code} not in the run");
}

it('nets materials against stock minus released work orders plus inbound, with need and order-by dates (D-124)', function () {
    $f = mpsFixture($this, $this->t);
    // coil bought with 2 working days lead time; 1,000 nuts arriving the Saturday before week B
    spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $f['coil']['id'], 'purchaseLeadTimeDays' => 2])->assertCreated();
    $saturday = date('Y-m-d', strtotime($f['weekB'].' -2 days'));
    spa($this)->postJson('/api/v1/inventory/inbound-lines', ['plantId' => $f['plant'], 'supplierId' => $f['supplier'], 'itemId' => $f['nut']['id'], 'qty' => '1000',
        'expectedDate' => $saturday])->assertCreated();
    [$mps] = mrpApprovedMps($this, $this->t, $f);

    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    expect($run['code'])->toMatch('/^MRP-[A-Z0-9_-]+-\d{4}-0001$/')->and($run['mpsNo'])->toBe($mps['code']);

    $fg = mrpItem($run, 'BRK-100');
    expect($fg)->toMatchArray(['kind' => 'MAKE', 'level' => 0])
        ->and(array_map(static fn (array $o) => [$o['source'], $o['qty'], $o['dueDate'], $o['bomVersionNo']], $fg['orders']))->toBe([['MPS', '270', $f['weekB'], 1]]);

    // coil: 270 × 0.5 = 135 KG on week B's Monday; stock on the racks − what the released WO still needs
    $coil = mrpItem($run, 'COIL-9');
    expect($coil)->toMatchArray(['kind' => 'BUY', 'level' => 1, 'leadTimeDays' => 2])
        ->and(array_map(static fn (array $q) => [$q['dueDate'], $q['qty'], $q['parentItemCode']], $coil['requirements']))->toBe([[$f['weekB'], '135', 'BRK-100']]);
    $free = (float) $coil['onHand'] - (float) $coil['committed'];
    $short = max(0, 135 - $free);
    expect($coil['orders'] === [] ? 0.0 : (float) $coil['orders'][0]['qty'])->toBe((float) $short);
    if ($short > 0) {
        // order by = need date − 2 working days (Mon–Sat): Saturday is a working day, so Friday
        expect($coil['orders'][0])->toMatchArray(['kind' => 'BUY', 'dueDate' => $f['weekB'], 'startDate' => date('Y-m-d', strtotime($f['weekB'].' -3 days'))]);
    }

    // nut: 540 needed, the inbound 1,000 arrives before: nothing short, the inbound shows as supply
    $nut = mrpItem($run, 'NUT-9');
    expect(array_column($nut['requirements'], 'qty'))->toBe(['540'])
        ->and(array_map(static fn (array $s) => [$s['kind'], $s['date'], $s['qty']], $nut['supplies']))->toBe([['INBOUND', $saturday, '1000']])
        ->and($nut['orders'])->toBe([]);

    spa($this)->getJson('/api/v1/query/planning.mrp_runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plannedOrderCount', 1);
    // a run is a snapshot: nothing is added to it later, also in SQL
    try {
        asApp($this->t, (string) $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->insert(
            'insert into mrp_pegs (tenant_id, mrp_run_id, order_id, qty) values (?, ?, ?, 1)', [$this->t['tenant'], $run['id'], $fg['orders'][0]['id']]));
        $this->fail('changed a past run');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('written once, in the transaction that runs it');
    }
});

it('plans a semi-finished component as a child work order after netting its stock, exploding it in turn (Option M, D-059)', function () {
    $f = mpsFixture($this, $this->t);
    // engineering: SF-100 (blank) from coil 0.5 KG; BRK-100 from tomorrow = 1 SF-100 + 2 nuts
    rtSwitch($this, $this->t, 'CSA');
    $pcs = $f['nut']['baseUomId'];
    $sf = spa($this)->postJson('/api/v1/core/items', ['code' => 'SF-100', 'name' => 'Bracket blank', 'itemType' => 'SEMI_FINISHED', 'baseUomId' => $pcs])->assertCreated()->json('data');
    $tomorrow = date('Y-m-d', strtotime($f['today'].' +1 day'));
    $bom = function (string $item, array $lines, string $from) {
        $b = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $item, 'effectiveFrom' => $from])->assertCreated()->json('data');
        $b = spa($this)->putJson("/api/v1/planning/bom-versions/{$b['id']}/lines", ['lines' => $lines], ['If-Match' => '"'.$b['version'].'"'])->assertOk()->json('data');
        spa($this)->postJson("/api/v1/planning/bom-versions/{$b['id']}/actions/submit")->assertOk();

        return $b;
    };
    $sfBom = $bom($sf['id'], [['lineNo' => 10, 'componentItemId' => $f['coil']['id'], 'grossQtyPer' => '0.5', 'uomId' => $f['coil']['baseUomId']]], $f['today']);
    $fgBom = $bom($f['item']['id'], [
        ['lineNo' => 10, 'componentItemId' => $sf['id'], 'grossQtyPer' => '1', 'uomId' => $pcs],
        ['lineNo' => 20, 'componentItemId' => $f['nut']['id'], 'grossQtyPer' => '2', 'uomId' => $pcs],
    ], $tomorrow);
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/planning/bom-versions/{$sfBom['id']}/actions/approve")->assertOk();
    spa($this)->postJson("/api/v1/planning/bom-versions/{$fgBom['id']}/actions/approve")->assertOk();
    mpsAs($this, $this->t, $f['ppc']);
    // a safety stock of 10 makes the MPS plan SF-100 itself (independent), on top of which the FG needs 270 of it
    spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $sf['id'], 'productionLeadTimeDays' => 1, 'safetyStock' => '10'])->assertCreated();
    [$mps] = mrpApprovedMps($this, $this->t, $f);

    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    expect(mrpItem($run, 'BRK-100')['orders'][0]['bomVersionNo'])->toBe(2);
    // SF-100: its MPS order (safety stock 10) stays, and the FG's 270 become a planned child WO due on the FG order's start,
    // started 1 working day before (Saturday)
    $blank = mrpItem($run, 'SF-100');
    expect($blank)->toMatchArray(['kind' => 'MAKE', 'level' => 1])
        ->and(collect($blank['orders'])->where('source', 'MPS')->pluck('qty')->all())->toBe(['10'])
        ->and(array_values(array_map(static fn (array $o) => [$o['kind'], $o['qty'], $o['dueDate'], $o['startDate'], $o['bomVersionNo']],
            array_filter($blank['orders'], static fn (array $o) => $o['source'] === 'MRP'))))
        ->toBe([['MAKE', '270', $f['weekB'], date('Y-m-d', strtotime($f['weekB'].' -2 days')), 1]]);
    // coil now comes from the blank (level 2), needed on the blank's start date
    $coil = mrpItem($run, 'COIL-9');
    expect($coil['level'])->toBe(2)
        ->and(collect($coil['requirements'])->firstWhere('qty', '135'))->toMatchArray(['dueDate' => date('Y-m-d', strtotime($f['weekB'].' -2 days')), 'parentItemCode' => 'SF-100'])
        ->and(collect($coil['requirements'])->pluck('qty')->sort()->values()->all())->toBe(['5', '135']);
    expect(array_column(mrpItem($run, 'NUT-9')['requirements'], 'qty'))->toBe(['540']);
});

it('runs only from the plant\'s approved MPS, by a planner; materials and others read it', function () {
    $f = mpsFixture($this, $this->t);
    $draft = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $draft['id']])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    mpsAct($this, $draft, 'cancel')->assertOk();
    [$mps] = mrpApprovedMps($this, $this->t, $f);
    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    $mat = rtSwitch($this, $this->t, 'MAT');
    spa($this)->getJson("/api/v1/planning/mrp-runs/{$run['id']}")->assertOk();
    spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertForbidden();
    try {
        asApp($this->t, (string) $mat['id'], fn (EoraPostgresConnection $db) => $db->insert(
            'insert into mrp_runs (tenant_id, plant_id, mps_version_id, run_no, plan_date, horizon_start, horizon_weeks) values (?, ?, ?, ?, ?, ?, 12)',
            [$this->t['tenant'], $f['plant'], $mps['id'], 'MRP-X-2026-9999', $f['today'], $mps['horizonStart']]));
        $this->fail('ran MRP without the permission');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('run by a planner of the plant');
    }
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][1]]);
    spa($this)->getJson("/api/v1/planning/mrp-runs/{$run['id']}")->assertForbidden();
});

it('releases planned work orders of the latest run to WO Drafts that count as supply from then on (D-125)', function () {
    $f = mpsFixture($this, $this->t);
    [$mps, $csa] = mrpApprovedMps($this, $this->t, $f);
    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->assertJsonPath('data.releasable', true)->json('data');
    $fg = mrpItem($run, 'BRK-100')['orders'][0];
    $shortage = collect(mrpItem($run, 'COIL-9')['orders'])->first();
    if ($shortage !== null) {
        spa($this)->postJson("/api/v1/planning/mrp-runs/{$run['id']}/actions/release", ['orderIds' => [$shortage['id']]])->assertStatus(422);
    }

    $run = spa($this)->postJson("/api/v1/planning/mrp-runs/{$run['id']}/actions/release", ['orderIds' => [$fg['id']]])->assertOk()->json('data');
    $wo = mrpItem($run, 'BRK-100')['orders'][0]['workOrder'];
    expect($wo)->toMatchArray(['status' => 'DRAFT'])->and($wo['code'])->toMatch('/^WO-/');
    $order = spa($this)->getJson("/api/v1/planning/work-orders/{$wo['id']}")->assertOk()->json('data');
    expect($order)->toMatchArray(['qty' => '270', 'dueDate' => $f['weekB'], 'reasonCode' => 'PLANNED', 'plannedFrom' => $run['code'], 'scheduleLineId' => null]);
    // released once: again from this run, or from a later run of the same MPS, is refused
    spa($this)->postJson("/api/v1/planning/mrp-runs/{$run['id']}/actions/release", ['orderIds' => [$fg['id']]])->assertStatus(409);
    $next = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    spa($this)->postJson("/api/v1/planning/mrp-runs/{$run['id']}/actions/release", ['orderIds' => [$fg['id']]])->assertStatus(409)->assertJsonPath('title', 'Not the current plan');
    spa($this)->postJson("/api/v1/planning/mrp-runs/{$next['id']}/actions/release", ['orderIds' => [mrpItem($next, 'BRK-100')['orders'][0]['id']]])->assertStatus(409)
        ->assertJsonPath('title', 'Already released');
    // its components are committed through the BOM until it is released: coil 135 more than before
    expect((float) mrpItem($next, 'COIL-9')['committed'] - (float) mrpItem($run, 'COIL-9')['committed'])->toBe(135.0);

    // the planner may change quantity and due date in the Draft, never its item or reason
    spa($this)->patchJson("/api/v1/planning/work-orders/{$wo['id']}", ['reasonCode' => 'URGENT_ORDER'], ['If-Match' => '"'.$order['version'].'"'])->assertStatus(422)->assertJsonValidationErrors(['reasonCode']);
    spa($this)->patchJson("/api/v1/planning/work-orders/{$wo['id']}", ['qty' => '300'], ['If-Match' => '"'.$order['version'].'"'])->assertOk()->assertJsonPath('data.qty', '300');
    try {
        asApp($this->t, (string) $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update("update work_orders set reason_code = 'URGENT_ORDER' where id = ?", [$wo['id']]));
        $this->fail('changed the source of a planned work order');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('keeps its item and source');
    }

    // the next MPS counts the Draft from the plan as supply: week B needs nothing more
    $again = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    expect(collect($again['items'][0]['plannedOrders'])->firstWhere('weekStart', $f['weekB']))->toBeNull()
        ->and(collect($again['items'][0]['supplies'])->pluck('reference')->all())->toContain($wo['code']);
});

it('refuses a release from a run that is not the current plan, or by someone who does not plan', function () {
    $f = mpsFixture($this, $this->t);
    [$mps] = mrpApprovedMps($this, $this->t, $f);
    $run = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    $fg = mrpItem($run, 'BRK-100')['orders'][0];
    rtSwitch($this, $this->t, 'MAT');
    spa($this)->postJson("/api/v1/planning/mrp-runs/{$run['id']}/actions/release", ['orderIds' => [$fg['id']]])->assertForbidden();
    $csa = rtSwitch($this, $this->t, 'CSA');
    try {
        asApp($this->t, (string) $csa['id'], fn (EoraPostgresConnection $db) => $db->insert(
            "insert into work_orders (tenant_id, plant_id, wo_no, item_id, uom_id, qty, due_date, reason_code, mrp_order_id)
             values (?, ?, 'WO-X-2026-9999', ?, ?, 270, ?, 'PLANNED', ?)",
            [$this->t['tenant'], $f['plant'], $f['item']['id'], $f['item']['baseUomId'], $f['weekB'], $fg['id']]));
        $this->fail('released without the permission');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('by a planner of');
    }
});

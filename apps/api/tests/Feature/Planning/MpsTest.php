<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M5 step 3 (doc 34 §1.3, D-123, D-046, doc 23 #51–#68): the MPS per plant through the API. The netting rules themselves
 * (#51, #53, #58, #65 F-1, #66, #67) are unit tested in tests/Unit/MpsEngineTest.php.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

function mpsAs(object $test, array $t, array $u): void
{
    spa($test)->postJson('/api/v1/auth/logout')->assertNoContent();
    signIn($test, $u, $t);
}

function mpsAct(object $test, array $m, string $action, array $body = [])
{
    return spa($test)->postJson("/api/v1/planning/mps-versions/{$m['id']}/actions/{$action}", $body, ['If-Match' => '"'.$m['version'].'"']);
}

/**
 * dlFixture (180 PCS of BRK-100 in the FG warehouse, a firm line of 100 three days ahead) plus an active forecast of the
 * customer: 150 in that week (100 consumed by the firm line, 50 net) and 300 two weeks later. Signed in as the planner.
 *
 * @return array<string, mixed>
 */
function mpsFixture(object $test, array $t): array
{
    $f = dlFixture($test, $t);
    mpsAs($test, $t, $f['ppc']);
    $weekA = date('Y-m-d', strtotime('monday this week', strtotime($f['today'].' +3 days')));
    $weekB = date('Y-m-d', strtotime($weekA.' +14 days'));
    $fc = spa($test)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $fc = spa($test)->putJson("/api/v1/demand/forecast-versions/{$fc['id']}/lines", ['lines' => [
        ['customerItemId' => $f['part'], 'weekStart' => $weekA, 'qty' => '150'],
        ['customerItemId' => $f['part'], 'weekStart' => $weekB, 'qty' => '300'],
    ]], ['If-Match' => '"'.$fc['version'].'"'])->assertOk()->json('data');
    spa($test)->postJson("/api/v1/demand/forecast-versions/{$fc['id']}/actions/activate", [], ['If-Match' => '"'.$fc['version'].'"'])->assertOk();
    // the fixture signed in nine new users; the tests below add their own within the invitation limit
    RateLimiter::clear(md5('invitation-accept'.'invite:ip:'.app(SecurityHasher::class)->hash('127.0.0.1')));

    return $f + ['weekA' => $weekA, 'weekB' => $weekB];
}

it('calculates a draft: FG on hand used once, firm and net forecast netted, lot-for-lot on the uncovered day, capacity not configured (D-123)', function () {
    $f = mpsFixture($this, $this->t);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.horizonWeeks', 12)->assertJsonPath('data.inputsChanged', false)
        ->assertJsonPath('data.itemCount', 1)->assertJsonPath('data.plannedQty', '270')->json('data');
    expect($m['code'])->toMatch('/^MPS-[A-Z0-9_-]+-\d{4}-0001$/')
        ->and($m['horizonStart'])->toBe(date('Y-m-d', strtotime('monday this week', strtotime($f['today']))));
    $item = $m['items'][0];
    expect($item)->toMatchArray(['itemCode' => 'BRK-100', 'onHand' => '180', 'outOfScopeAllocated' => '0', 'safetyStock' => null, 'pendingQc' => '0', 'leadTimeDays' => null])
        ->and(array_map(static fn (array $d) => [$d['kind'], $d['qty']], $item['demands']))->toEqualCanonicalizing([['FIRM', '100'], ['FORECAST', '50'], ['FORECAST', '300']])
        // the WO of 100 already produced 180 good: nothing remaining as supply (F-6)
        ->and($item['supplies'])->toBe([])
        ->and($item['plannedOrders'])->toHaveCount(1);
    $order = $item['plannedOrders'][0];
    expect($order)->toMatchArray(['weekStart' => $f['weekB'], 'qty' => '270', 'calcQty' => '270', 'dueDate' => $f['weekB'], 'startDate' => $f['weekB'], 'adjusted' => false])
        ->and($order['warnings'])->toBe(['LEAD_TIME_NOT_CONFIGURED'])
        ->and(array_column($order['pegs'], 'qty'))->toBe(['270']);
    $weekB = collect($item['buckets'])->firstWhere('weekStart', $f['weekB']);
    expect($weekB)->toMatchArray(['opening' => '30', 'forecast' => '300', 'netRequirement' => '270', 'closing' => '0']);
    // no shift calendar: capacity is not configured per work center, no percentage
    expect(array_map(static fn (array $c) => [$c['workCenterCode'], $c['weekStart'], $c['loadHours'], $c['availableHours'], $c['loadPercent'], $c['status']], $m['capacity']))
        ->toBe([['PRS-01', $f['weekB'], '0.67', null, null, 'NOT_CONFIGURED'], ['WLD-01', $f['weekB'], '0.9', null, null, 'NOT_CONFIGURED']]);
    spa($this)->getJson('/api/v1/query/planning.mps_versions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plannedOrderCount', 1);

    // one open MPS per plant
    spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // with a shift calendar and hours per shift the load is a percentage; above 100 % it warns
    AccessFixtures::run($this->t, function (EoraPostgresConnection $db) use ($f) {
        $cal = (string) $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, null, 'STD', 'Standard', '2026-01-01') returning id", [$this->t['tenant']])->id;
        $db->insert("insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, 'S1', 'Day', '07:00', '15:00')", [$this->t['tenant'], $cal]);
        $db->update('update work_centers set available_hours_per_shift = 0.1 where id = ?', [$f['wc']['id']]);
    });
    $m = mpsAct($this, $m, 'recalculate')->assertOk()->json('data');
    expect(array_map(static fn (array $c) => [$c['workCenterCode'], $c['availableHours'], $c['loadPercent'], $c['status']], $m['capacity']))
        ->toBe([['PRS-01', '0.6', 111, 'OVERLOAD'], ['WLD-01', null, null, 'NOT_CONFIGURED']])
        ->and($m['overloadCount'])->toBe(1);
});

it('lets the planner adjust a planned order with a reason, and a second person approve it while its data is unchanged (SoD-07, D-123)', function () {
    $f = mpsFixture($this, $this->t);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $order = $m['items'][0]['plannedOrders'][0];
    $adjust = fn (array $body, int $version) => spa($this)->patchJson("/api/v1/planning/mps-versions/{$m['id']}/planned-orders/{$order['id']}", $body, ['If-Match' => '"'.$version.'"']);
    $earlier = date('Y-m-d', strtotime($f['weekB'].' -2 days'));
    $adjust(['qty' => '300', 'dueDate' => $earlier], $m['version'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $adjust(['qty' => '300.5', 'dueDate' => $earlier, 'reason' => 'Round up'], $m['version'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $adjust(['qty' => '300', 'dueDate' => '2020-01-01', 'reason' => 'Round up'], $m['version'])->assertStatus(422)->assertJsonValidationErrors(['dueDate']);
    $m = $adjust(['qty' => '300', 'dueDate' => $earlier, 'reason' => 'Full die run of 300'], $m['version'])->assertOk()->assertJsonPath('data.adjustedCount', 1)->json('data');
    expect($m['items'][0]['plannedOrders'][0])->toMatchArray(['qty' => '300', 'calcQty' => '270', 'dueDate' => $earlier, 'calcDueDate' => $f['weekB'], 'adjusted' => true,
        'adjustReason' => 'Full die run of 300']);
    $adjust(['qty' => '310', 'dueDate' => $earlier, 'reason' => 'Again'], $m['version'] - 1)->assertStatus(412);

    $m = mpsAct($this, $m, 'submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->json('data');
    $adjust(['qty' => '310', 'dueDate' => $earlier, 'reason' => 'Too late'], $m['version'])->assertStatus(409);
    // the planner holding the approver role too is still the preparer
    AccessFixtures::grant($this->t, $f['ppc']['id'], 'CSA', ['TENANT']);
    mpsAct($this, $m, 'approve')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    // the data changes after the calculation: approval is refused, the approver returns it
    spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $f['item']['id'], 'safetyStock' => '20'])->assertCreated();
    $csa = rtSwitch($this, $this->t, 'CSA');
    spa($this)->getJson("/api/v1/planning/mps-versions/{$m['id']}")->assertOk()->assertJsonPath('data.inputsChanged', true);
    mpsAct($this, $m, 'approve')->assertStatus(409)->assertJsonPath('code', 'MPS_INPUTS_CHANGED');
    mpsAct($this, $m, 'return')->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $m = mpsAct($this, $m, 'return', ['reason' => 'Safety stock was set; recalculate'])->assertOk()
        ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.returnReason', 'Safety stock was set; recalculate')->json('data');

    // recalculated: the adjustment is replaced, safety stock 20 raises the requirement to 290
    mpsAs($this, $this->t, $f['ppc']);
    $m = mpsAct($this, $m, 'recalculate')->assertOk()->assertJsonPath('data.plannedQty', '290')->assertJsonPath('data.adjustedCount', 0)->assertJsonPath('data.inputsChanged', false)->json('data');
    expect($m['items'][0]['safetyStock'])->toBe('20');
    $m = mpsAct($this, $m, 'submit')->assertOk()->json('data');
    mpsAs($this, $this->t, $csa);
    $approved = mpsAct($this, $m, 'approve')->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.inputsChanged', null)->json('data');
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select(
        'select action from audit_events where entity_id = ? order by occurred_at, id', [$m['id']]), 'action')))
        ->toBe(['mps.calculated', 'mps.planned_order_adjusted', 'mps.submitted', 'mps.returned', 'mps.recalculated', 'mps.submitted', 'mps.approved']);

    // the next approved MPS supersedes it
    mpsAs($this, $this->t, $f['ppc']);
    $next = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $next = mpsAct($this, $next, 'submit')->assertOk()->json('data');
    mpsAs($this, $this->t, $csa);
    mpsAct($this, $next, 'approve')->assertOk();
    spa($this)->getJson("/api/v1/planning/mps-versions/{$approved['id']}")->assertOk()->assertJsonPath('data.status', 'SUPERSEDED');

    // history in the database too: rows of an approved MPS never change, and the preparer never approves in SQL
    try {
        asApp($this->t, (string) $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->delete('delete from mps_pegs where mps_version_id = ?', [$next['id']]));
        $this->fail('changed an approved MPS');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('only a draft MPS changes its calculation');
    }
});

it('keeps everyone who adjusted a planned order out of the approval, even after another planner adjusted it again (SoD-07, Codex #87)', function () {
    $f = mpsFixture($this, $this->t);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $order = $m['items'][0]['plannedOrders'][0];
    $adjust = fn (string $qty, int $version) => spa($this)->patchJson("/api/v1/planning/mps-versions/{$m['id']}/planned-orders/{$order['id']}",
        ['qty' => $qty, 'dueDate' => $order['dueDate'], 'reason' => 'Die run size'], ['If-Match' => '"'.$version.'"']);
    // a planner who also approves adjusts first; the calculating planner adjusts again and submits
    $both = rtSwitch($this, $this->t, 'PPC');
    AccessFixtures::grant($this->t, $both['id'], 'CSA', ['TENANT']);
    $m = $adjust('100', $m['version'])->assertOk()->json('data');
    // the grid follows the adjusted plan: 100 of 270 leaves a shortage of 170 in that week
    expect(collect($m['items'][0]['buckets'])->firstWhere('weekStart', $f['weekB']))->toMatchArray(['plannedQty' => '100', 'netRequirement' => '270', 'closing' => '-170']);
    mpsAs($this, $this->t, $f['ppc']);
    $m = $adjust('300', $m['version'])->assertOk()->json('data');
    $m = mpsAct($this, $m, 'submit')->assertOk()->json('data');
    mpsAs($this, $this->t, $both);
    mpsAct($this, $m, 'approve')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    try {
        asApp($this->t, (string) $both['id'], fn (EoraPostgresConnection $db) => $db->update(
            "update mps_versions set status = 'APPROVED', approved_at = now(), approved_by = ? where id = ?", [$both['id'], $m['id']]));
        $this->fail('approved by an earlier adjuster');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('did not prepare it (SoD-07)');
    }
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select count(*) as n from mps_contributors where mps_version_id = ?', [$m['id']])->n))->toBe(4);
});

it('keeps the MPS to the planners and approvers of its plant, and lets a planner cancel a draft', function () {
    $f = mpsFixture($this, $this->t);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    // a planner of the other plant neither calculates nor sees it
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][1]]);
    spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertForbidden();
    spa($this)->getJson("/api/v1/planning/mps-versions/{$m['id']}")->assertForbidden();
    // the approver neither calculates nor submits
    $csa = rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertForbidden();
    mpsAct($this, $m, 'submit')->assertForbidden();
    try {
        asApp($this->t, (string) $csa['id'], fn (EoraPostgresConnection $db) => $db->update("update mps_versions set status = 'SUBMITTED', submitted_at = now(), submitted_by = ? where id = ?", [$csa['id'], $m['id']]));
        $this->fail('submitted by the approver');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('submitted by a planner of the plant');
    }
    mpsAs($this, $this->t, $f['ppc']);
    mpsAct($this, $m, 'cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->assertJsonPath('data.code', fn (string $c) => str_ends_with($c, '-0002'));
});

it('does not count stock allocated to demand inside the horizon twice (#52, F-2)', function () {
    $f = mpsFixture($this, $this->t);
    mpsAs($this, $this->t, $f['dlv']);
    $balance = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select b.id from stock_balances b join locations l on l.id = b.location_id where b.item_id = ? and l.code = 'FG-01'", [$f['item']['id']])->id);
    dlAllocate($this, $f, [['stockLineId' => $balance, 'qty' => '60']])->assertCreated();
    mpsAs($this, $this->t, $f['ppc']);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    expect($m['items'][0])->toMatchArray(['onHand' => '180', 'outOfScopeAllocated' => '0'])
        ->and($m['plannedQty'])->toBe('270');
});

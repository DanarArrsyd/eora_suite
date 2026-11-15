<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 1 (doc 33 §1.1, D-107, D-034, BR-DEM-05/06/10, doc 16 §12): Sales Orders with Delivery Schedule Lines.
 * Owner rules 2026-11-12: customer PO as a reference on the line (one active SO line per PO line), line quantity =
 * sum of its schedule lines, finished items through an active customer part mapping, dates today or later, PPC
 * confirms alone, a confirmed order only gains or cancels lines, drafts are cancelled, never deleted.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t) + soFixture($this->t);
});

/** Customers OEM-A / OEM-B with customer part mappings of the finished item and of a raw item. */
function soFixture(array $t): array
{
    return rcRun($t, function (EoraPostgresConnection $db) use ($t) {
        $customer = fn (string $code) => (string) $db->selectOne('insert into customers (tenant_id, code, name) values (?, ?, ?) returning id', [$t['tenant'], $code, "Customer {$code}"])->id;
        $oemA = $customer('OEM-A');
        $oemB = $customer('OEM-B');
        $fg = (string) $db->selectOne("select id from items where code = 'FG-1'")->id;
        $coil = (string) $db->selectOne("select id from items where code = 'COIL-1'")->id;
        $map = fn (string $c, string $item, string $part) => (string) $db->selectOne(
            "insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) values (?, ?, ?, ?, '2026-01-01') returning id",
            [$t['tenant'], $c, $item, $part],
        )->id;

        return ['oemA' => $oemA, 'oemB' => $oemB, 'partA' => $map($oemA, $fg, 'A-77101'), 'partB' => $map($oemB, $fg, 'B-552'), 'rawPart' => $map($oemA, $coil, 'A-COIL')];
    });
}

function soCreate(object $test, array $f, array $body = [])
{
    return spa($test)->postJson('/api/v1/demand/sales-orders', $body + ['plantId' => $f['plant'], 'customerId' => $f['oemA']]);
}

function soLine(array $f, string $po = 'PO-1001', string $poLine = '10', array $schedules = [['+3', '250'], ['+10', '250']], ?string $part = null): array
{
    return [
        'customerItemId' => $part ?? $f['partA'], 'customerPoNo' => $po, 'customerPoLine' => $poLine,
        'schedules' => array_map(fn ($s) => ['requirementDate' => date('Y-m-d', strtotime($f['today'].' '.$s[0].' days')), 'qty' => $s[1]], $schedules),
    ];
}

function soWith(object $test, string $method, string $uri, array $body, int $version)
{
    return spa($test)->json($method, $uri, $body, ['If-Match' => '"'.$version.'"']);
}

it('drafts, fills and confirms an order into firm demand; the line quantity is the sum of its schedule lines', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $year = substr($f['today'], 0, 4);
    $draft = soCreate($this, $f, ['note' => 'Launch order'])->assertCreated()->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.code', "SO-P1-{$year}-0001")->assertJsonPath('data.customerCode', 'OEM-A');
    $id = $draft->json('data.id');

    $filled = soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$id}/lines", ['lines' => [soLine($f), soLine($f, 'PO-1001', '20', [['+5', '40']])]], $draft->json('data.version'))
        ->assertOk()->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.lines.0.qty', '500')->assertJsonPath('data.lines.0.customerPartNo', 'A-77101')->assertJsonPath('data.lines.0.itemCode', 'FG-1')
        ->assertJsonPath('data.lines.0.schedules.0.status', 'DRAFT')->assertJsonPath('data.lines.1.qty', '40')->assertJsonPath('data.lineCount', 2);

    $confirmed = soWith($this, 'POST', "/api/v1/demand/sales-orders/{$id}/actions/confirm", [], $filled->json('data.version'))
        ->assertOk()->assertJsonPath('data.plantToday', $f['today'])->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.lines.0.schedules.0.status', 'FIRM')
        ->assertJsonPath('data.lines.0.schedules.0.outstandingQty', '250')->assertJsonPath('data.nextDueDate', date('Y-m-d', strtotime($f['today'].' +3 days')));
    expect($confirmed->json('data.confirmedByName'))->not->toBeNull();

    spa($this)->getJson('/api/v1/query/demand.sales_orders?q=po-1001')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customerPoNos', 'PO-1001');
    // a reference keyed in lower case is found whatever case is searched
    $lower = soCreate($this, $f)->assertCreated()->json('data');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$lower['id']}/lines", ['lines' => [soLine($f, 'po-7007', '1')]], $lower['version'])->assertOk();
    spa($this)->getJson('/api/v1/query/demand.sales_orders?q=PO-70')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customerPoNos', 'po-7007');
    spa($this)->getJson("/api/v1/demand/sales-orders/{$id}")->assertOk()->assertHeader('ETag', '"'.$confirmed->json('data.version').'"');
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => array_column($db->select('select event_type from outbox_events where aggregate_id = ? order by id', [$id]), 'event_type')))
        ->toBe(['demand.changed'])
        ->and(rcRun($this->t, fn (EoraPostgresConnection $db) => array_column($db->select('select action from audit_events where entity_id = ? order by occurred_at, id', [$id]), 'action')))
        ->toBe(['sales_order.created', 'sales_order.lines_replaced', 'sales_order.confirmed']);
})->group('integration', 'demand');

it('takes only an active finished customer part of the order\'s customer, today or later, each customer PO line once (BR-DEM-05)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $a = soCreate($this, $f)->assertCreated()->json('data');
    $put = fn (array $lines, int $v) => soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$a['id']}/lines", ['lines' => $lines], $v);

    $put([soLine($f, part: $f['partB'])], $a['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.0.customerItemId']);
    $put([soLine($f, part: $f['rawPart'])], $a['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.0.customerItemId']);
    $put([soLine($f, schedules: [['-1', '5']])], $a['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.0.schedules.0.requirementDate']);
    $put([soLine($f, schedules: [['+1', '2.5']])], $a['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.0.schedules.0.qty']);
    $put([soLine($f), soLine($f, 'po-1001', '10')], $a['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.1.customerPoLine']);
    $v = $put([soLine($f)], $a['version'])->assertOk()->json('data.version');
    $put([soLine($f)], $a['version'])->assertStatus(412);

    // the same customer PO line on a second order is refused while the first holds it; free again once that one is cancelled
    $b = soCreate($this, $f)->assertCreated()->json('data');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$b['id']}/lines", ['lines' => [soLine($f)]], $b['version'])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.customerPoLine' => "Customer PO PO-1001 line 10 is already on {$a['code']}."]);
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$a['id']}/actions/cancel", ['reason' => 'Customer withdrew the PO'], $v)
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.lines.0.status', 'CANCELLED')
        ->assertJsonPath('data.lines.0.schedules.0.status', 'CANCELLED');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$b['id']}/lines", ['lines' => [soLine($f)]], $b['version'])->assertOk();

    // the same PO number and line of another customer is another PO
    $c = soCreate($this, $f, ['customerId' => $f['oemB']])->assertCreated()->json('data');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$c['id']}/lines", ['lines' => [soLine($f, part: $f['partB'])]], $c['version'])->assertOk();
    // an item that is no longer finished is not confirmed into demand
    rcRun($this->t, fn (EoraPostgresConnection $db) => $db->update("update items set item_type = 'SEMI_FINISHED' where code = 'FG-1'"));
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$c['id']}/actions/confirm", [], spa($this)->getJson("/api/v1/demand/sales-orders/{$c['id']}")->json('data.version'))
        ->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_PART_INACTIVE');
    rcRun($this->t, fn (EoraPostgresConnection $db) => $db->update("update items set item_type = 'FINISHED' where code = 'FG-1'"));
    // an empty draft is not confirmed
    $d = soCreate($this, $f)->assertCreated()->json('data');
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$d['id']}/actions/confirm", [], $d['version'])->assertStatus(409);
})->group('integration', 'demand');

it('cancels firm schedule lines with a reason and the line with its last one; refuses a past date and an allocated line', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $so = soCreate($this, $f)->assertCreated()->json('data');
    $uri = "/api/v1/demand/sales-orders/{$so['id']}";
    $v = soWith($this, 'PUT', "{$uri}/lines", ['lines' => [soLine($f)]], $so['version'])->json('data.version');
    $v = soWith($this, 'POST', "{$uri}/actions/confirm", [], $v)->assertOk()->json('data.version');

    soWith($this, 'PUT', "{$uri}/lines", ['lines' => [soLine($f)]], $v)->assertStatus(409);
    soWith($this, 'POST', "{$uri}/actions/cancel", ['reason' => 'No longer needed'], $v)->assertStatus(409);
    // the order's own customer PO line, in other letter case, is not a second demand (BR-DEM-05, doc 14 #28)
    soWith($this, 'POST', "{$uri}/lines", soLine($f, 'po-1001', '10', [['+7', '60']]), $v)
        ->assertStatus(422)->assertJsonValidationErrors(['customerPoLine' => 'This customer PO line is already on this order.']);

    $added = soWith($this, 'POST', "{$uri}/lines", soLine($f, 'PO-1002', '10', [['+7', '60']]), $v)
        ->assertOk()->assertJsonPath('data.lines.1.lineNo', 2)->assertJsonPath('data.lines.1.schedules.0.status', 'FIRM');
    $line1 = $added->json('data.lines.0');
    $v = $added->json('data.version');
    soWith($this, 'POST', "{$uri}/schedule-lines", ['lineId' => $line1['id'], 'requirementDate' => date('Y-m-d', strtotime($f['today'].' -1 days')), 'qty' => '5'], $v)
        ->assertStatus(422)->assertJsonValidationErrors(['requirementDate']);
    $more = soWith($this, 'POST', "{$uri}/schedule-lines", ['lineId' => $line1['id'], 'requirementDate' => date('Y-m-d', strtotime($f['today'].' +20 days')), 'qty' => '100'], $v)
        ->assertOk()->assertJsonPath('data.lines.0.qty', '600');
    $schedules = $more->json('data.lines.0.schedules');
    expect(array_column($schedules, 'scheduleNo'))->toBe([1, 2, 3]);
    $v = $more->json('data.version');

    // FG allocated or picked for a schedule line blocks its cancel (SCHEDULE_LINE_ALLOCATED): tests/Feature/Delivery (D-115)

    $one = soWith($this, 'POST', "{$uri}/schedule-lines/{$schedules[1]['id']}/actions/cancel", ['reason' => 'Customer pulled it in'], $v)
        ->assertOk()->assertJsonPath('data.lines.0.qty', '350')->assertJsonPath('data.lines.0.status', 'ACTIVE');
    expect(collect($one->json('data.lines.0.schedules'))->firstWhere('id', $schedules[1]['id']))
        ->toMatchArray(['status' => 'CANCELLED', 'cancelReason' => 'Customer pulled it in', 'outstandingQty' => '0']);
    soWith($this, 'POST', "{$uri}/schedule-lines/{$schedules[1]['id']}/actions/cancel", ['reason' => 'Again'], $one->json('data.version'))->assertStatus(409);

    // the second line's only schedule line: the line is cancelled with it
    $last = $one->json('data.lines.1.schedules.0.id');
    soWith($this, 'POST', "{$uri}/schedule-lines/{$last}/actions/cancel", ['reason' => 'Part discontinued'], $one->json('data.version'))
        ->assertOk()->assertJsonPath('data.lines.1.status', 'CANCELLED')->assertJsonPath('data.lineCount', 1);
})->group('integration', 'demand');

it('keeps orders inside the plant scope and the confirm and edits with their permissions', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $so = soCreate($this, $f)->assertCreated()->json('data');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [soLine($f)]], $so['version'])->assertOk();
    $so = spa($this)->getJson("/api/v1/demand/sales-orders/{$so['id']}")->json('data');
    soCreate($this, $f, ['plantId' => $f['plant2']])->assertCreated();

    // a planner of plant 2 neither sees nor changes the plant 1 order
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $f['plant2']]);
    spa($this)->getJson("/api/v1/demand/sales-orders/{$so['id']}")->assertNotFound();
    spa($this)->getJson('/api/v1/query/demand.sales_orders')->assertOk()->assertJsonCount(1, 'data');
    soCreate($this, $f)->assertForbidden();
    // the customer PO line stays unique across plants (BR-DEM-05), but the plant 1 order is not named to this planner
    $own = soCreate($this, $f, ['plantId' => $f['plant2']])->assertCreated()->json('data');
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$own['id']}/lines", ['lines' => [soLine($f)]], $own['version'])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.customerPoLine' => 'Customer PO PO-1001 line 10 is already on another order.']);

    // a viewer (FGW: view only) reads but neither edits nor confirms
    rtSwitch($this, $this->t, 'FGW');
    spa($this)->getJson("/api/v1/demand/sales-orders/{$so['id']}")->assertOk();
    soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [soLine($f)]], $so['version'])->assertForbidden();
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], $so['version'])->assertForbidden();
})->group('integration', 'demand');

it('refuses in the database what the lifecycle forbids: editing a firm line, deleting from a confirmed order, reusing a PO line', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $so = soCreate($this, $f)->assertCreated()->json('data');
    $v = soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [soLine($f)]], $so['version'])->json('data.version');
    $confirmed = soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], $v)->assertOk()->json('data');
    $schedule = $confirmed['lines'][0]['schedules'][0]['id'];

    $refused = function (string $sql, array $bindings, string $code) {
        try {
            rcRun($this->t, fn (EoraPostgresConnection $db) => $db->statement($sql, $bindings));
            $this->fail("not refused: {$sql}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe($code);
        }
    };
    $refused('update delivery_schedule_lines set required_qty = 1 where id = ?', [$schedule], '42501');
    $refused('delete from delivery_schedule_lines where id = ?', [$schedule], '23514');
    $refused("update sales_order_headers set status = 'DRAFT' where id = ?", [$so['id']], '23514');
    $refused(
        'insert into sales_order_lines (tenant_id, sales_order_id, customer_id, plant_id, line_no, customer_item_id, item_id, uom_id, customer_po_no, customer_po_line)
         select tenant_id, sales_order_id, customer_id, plant_id, 9, customer_item_id, item_id, uom_id, lower(customer_po_no), customer_po_line from sales_order_lines where sales_order_id = ?',
        [$so['id']], '23505',
    );
})->group('integration', 'demand');

it('revises a firm schedule line: superseded with its delivered quantity, new lines take the next revision and the outstanding (D-122, BR-DEM-11)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    $so = soCreate($this, $f)->assertCreated()->json('data');
    $filled = soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [soLine($f, schedules: [['+3', '250']])]], $so['version'])->assertOk()->json('data');
    $order = soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], $filled['version'])->assertOk()->json('data');
    $line = $order['lines'][0]['schedules'][0];
    $day = fn (int $d) => date('Y-m-d', strtotime($f['today']." +{$d} days"));
    $revise = fn (array $schedules, int $version, string $reason = 'Customer moved the date') => soWith($this, 'POST',
        "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$line['id']}/actions/revise", ['reason' => $reason, 'schedules' => $schedules], $version);

    $revise([['requirementDate' => $day(-1), 'qty' => '100']], $order['version'])->assertStatus(422)->assertJsonValidationErrors(['schedules.0.requirementDate']);
    $revise([['requirementDate' => $day(5), 'qty' => '100']], $order['version'], 'x')->assertStatus(422)->assertJsonValidationErrors(['reason']);
    // a split: 150 on day 5, 120 on day 9 (the customer also raised the total by 20)
    $revised = $revise([['requirementDate' => $day(5), 'qty' => '150'], ['requirementDate' => $day(9), 'qty' => '120']], $order['version'])->assertOk()->json('data');
    $schedules = collect($revised['lines'][0]['schedules'])->keyBy('id');
    expect($schedules[$line['id']])->toMatchArray(['status' => 'SUPERSEDED', 'revision' => 1, 'outstandingQty' => '0', 'deliveredQty' => '0'])
        ->and($revised['lines'][0]['qty'])->toBe('270');
    $new = $schedules->filter(fn ($s) => $s['status'] === 'FIRM')->values();
    expect($new->pluck('revision')->all())->toBe([2, 2])->and($new->pluck('qty')->all())->toBe(['150', '120'])
        ->and($schedules[$line['id']]['supersededById'])->toBe($new[0]['id']);
    // the 250 outstanding went 150 + 100 to the new lines, with the reason
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_map(fn ($r) => (array) $r, $db->select(
        'select trim_scale(qty)::text as qty, reason from schedule_reallocations where from_line_id = ? order by qty desc', [$line['id']]))))
        ->toBe([['qty' => '150', 'reason' => 'Customer moved the date'], ['qty' => '100', 'reason' => 'Customer moved the date']]);
    // each branch is revised again, one with a split: references stay unique per revision (Codex on #86)
    $again = fn (array $from, array $schedules, int $version) => soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$from['id']}/actions/revise",
        ['reason' => 'Customer changed again', 'schedules' => $schedules], $version);
    $second = $again($new[0], [['requirementDate' => $day(6), 'qty' => '100'], ['requirementDate' => $day(7), 'qty' => '50']], $revised['version'])->assertOk()->json('data');
    $third = $again($new[1], [['requirementDate' => $day(11), 'qty' => '120']], $second['version'])->assertOk()->json('data');
    expect(collect($third['lines'][0]['schedules'])->where('status', 'FIRM')->pluck('qty')->sort()->values()->all())->toBe(['50', '100', '120']);
    // a superseded line is history
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$line['id']}/actions/revise",
        ['reason' => 'Again', 'schedules' => [['requirementDate' => $day(6), 'qty' => '10']]], $third['version'])->assertStatus(409);

    // every firm successor cancelled: nothing was delivered, so the order line is cancelled too (Codex on #86)
    $version = $third['version'];
    foreach (collect($third['lines'][0]['schedules'])->where('status', 'FIRM') as $firm) {
        $version = soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$firm['id']}/actions/cancel", ['reason' => 'Order withdrawn'], $version)
            ->assertOk()->json('data.version');
    }
    spa($this)->getJson("/api/v1/demand/sales-orders/{$so['id']}")->assertOk()->assertJsonPath('data.lines.0.status', 'CANCELLED');

    // straight in the database: superseded without a newer revision, or by someone who does not plan
    try {
        AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update delivery_schedule_lines set status = 'SUPERSEDED', superseded_by = id where id = ?", [$new[1]['id']]));
        $this->fail('superseded without a newer revision');
    } catch (QueryException $e) {
        expect($e->getCode())->toBeIn(['23514', '42501']);
    }
})->group('integration', 'demand');

it('keeps forecast versions per customer and plant; firm demand of the same week consumes the forecast once (D-122, BR-DEM-08)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'PPC');
    // a firm order of OEM-A: 250 in the week of today + 3
    $so = soCreate($this, $f)->assertCreated()->json('data');
    $filled = soWith($this, 'PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [soLine($f, schedules: [['+3', '250']])]], $so['version'])->assertOk()->json('data');
    soWith($this, 'POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], $filled['version'])->assertOk();
    $due = date('Y-m-d', strtotime($f['today'].' +3 days'));
    $week = date('Y-m-d', strtotime('monday this week', strtotime($due)));
    $next = date('Y-m-d', strtotime($week.' +7 days'));

    $year = substr($f['today'], 0, 4);
    $v1 = spa($this)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['oemA']])->assertCreated()
        ->assertJsonPath('data.code', "FC-P1-{$year}-0001")->assertJsonPath('data.status', 'DRAFT')->json('data');
    spa($this)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['oemA']])->assertStatus(409);
    $put = fn (string $id, array $lines, int $version) => soWith($this, 'PUT', "/api/v1/demand/forecast-versions/{$id}/lines", ['lines' => $lines], $version);
    // only the customer's own parts; a day names its week
    $put($v1['id'], [['customerItemId' => $f['partB'], 'weekStart' => $due, 'qty' => '100']], $v1['version'])->assertStatus(422)->assertJsonValidationErrors(['lines.0.customerItemId']);
    $v1 = $put($v1['id'], [['customerItemId' => $f['partA'], 'weekStart' => $due, 'qty' => '400'], ['customerItemId' => $f['partA'], 'weekStart' => $next, 'qty' => '300']], $v1['version'])
        ->assertOk()->json('data');
    expect(array_map(fn ($l) => [$l['weekStart'], $l['qty'], $l['firmQty'], $l['netQty']], $v1['lines']))->toBe([[$week, '400', '250', '150'], [$next, '300', '0', '300']]);
    $v1 = soWith($this, 'POST', "/api/v1/demand/forecast-versions/{$v1['id']}/actions/activate", [], $v1['version'])->assertOk()->assertJsonPath('data.status', 'ACTIVE')->json('data');
    $put($v1['id'], [], $v1['version'])->assertStatus(409);

    // a new version from the active one; activated, it supersedes the old one
    $v2 = spa($this)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['oemA'], 'copyFromActive' => true])->assertCreated()
        ->assertJsonCount(2, 'data.lines')->assertJsonPath('data.lineCount', 2)->json('data');
    $v2 = soWith($this, 'POST', "/api/v1/demand/forecast-versions/{$v2['id']}/actions/activate", [], $v2['version'])->assertOk()->json('data');
    spa($this)->getJson("/api/v1/query/demand.forecast_versions?filter[id]={$v1['id']}")->assertOk()->assertJsonPath('data.0.status', 'SUPERSEDED');
    spa($this)->getJson('/api/v1/query/demand.forecast_lines?filter[versionStatus]=ACTIVE')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.netQty', '150');

    // a second part of the same customer and item: its forecast is not consumed by the first part's orders (Codex on #86)
    $alt = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) select tenant_id, customer_id, item_id, 'A-77101-ALT', '2026-01-01' from customer_items where id = ? returning id",
        [$f['partA']])->id);
    $draft = spa($this)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['oemA']])->assertCreated()->json('data');
    $alts = $put($draft['id'], [['customerItemId' => $f['partA'], 'weekStart' => $due, 'qty' => '100'], ['customerItemId' => $alt, 'weekStart' => $due, 'qty' => '100']], $draft['version'])
        ->assertOk()->json('data.lines');
    expect(collect($alts)->mapWithKeys(fn ($l) => [$l['customerPartNo'] => $l['netQty']])->all())->toBe(['A-77101' => '0', 'A-77101-ALT' => '100']);

    // only a planner writes a forecast
    rtSwitch($this, $this->t, 'DLV');
    spa($this)->getJson('/api/v1/query/demand.forecast_versions')->assertOk()->assertJsonCount(3, 'data');
    spa($this)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['oemB']])->assertForbidden();
    expect($v2['status'])->toBe('ACTIVE');
})->group('integration', 'demand');

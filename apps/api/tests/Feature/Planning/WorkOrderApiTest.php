<?php

declare(strict_types=1);

use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 2 (doc 33 §1.2, D-109, G-MPS-6, BR-PLN-01, doc 16 §1): manual Work Orders. PPC drafts a WO for a finished item
 * with a planning-exception reason, optionally pegged to a firm delivery; a second person releases it, which snapshots
 * the approved BOM and primary routing versions and writes the material lines.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

it('drafts a WO, asks for release, and a second person releases it with the BOM and routing snapshot and material lines', function () {
    $s = woFixture($this, $this->t);
    $year = substr($s['today'], 0, 4);
    $wo = woCreate($this, $s)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.code', "WO-P1-{$year}-0001")
        ->assertJsonPath('data.itemCode', 'BRK-100')->assertJsonPath('data.materials', [])->json('data');
    $sent = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->json('data');
    // the requester never releases; a planner has no approve permission at all
    woAct($this, $wo['id'], 'release', $sent['version'])->assertForbidden();

    $approver = rtSwitch($this, $this->t, 'CSA');
    $released = woAct($this, $wo['id'], 'release', $sent['version'])->assertOk()->assertJsonPath('data.status', 'RELEASED')
        ->assertJsonPath('data.bomVersionNo', 1)->assertJsonPath('data.routingCode', 'R1')->assertJsonPath('data.routingVersionNo', 1)
        ->assertJsonPath('data.standardYield', '0.98')->json('data');
    expect($released['materials'])->toHaveCount(2)
        ->and(array_map(fn ($m) => [$m['itemCode'], $m['requiredQty'], $m['uomCode']], $released['materials']))->toBe([['COIL-9', '50', 'KG'], ['NUT-9', '200', 'PCS']])
        ->and($released['releasedByName'])->not->toBeNull();
    // a released WO is not edited
    spa($this)->patchJson("/api/v1/planning/work-orders/{$wo['id']}", ['qty' => '120'], ['If-Match' => '"'.$released['version'].'"'])->assertStatus(409);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select('select event_type from outbox_events where aggregate_id = ? order by id', [$wo['id']]), 'event_type')))
        ->toBe(['wo.release_requested', 'wo.released']);
    spa($this)->getJson('/api/v1/query/planning.work_orders?q=brk')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'RELEASED');
    expect($approver)->toBeArray();
})->group('integration', 'planning');

it('refuses the requester as releaser in the database too', function () {
    $s = woFixture($this, $this->t);
    $wo = woCreate($this, $s)->assertCreated()->json('data');
    $sent = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');
    $requester = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select submitted_by from work_orders where id = ?', [$wo['id']])->submitted_by);
    try {
        asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update(
            "update work_orders set status = 'RELEASED', released_by = ?, released_at = now(), bom_version_id = ?, routing_version_id = ? where id = ?",
            [$requester, $s['bom']['id'], $s['rv']['id'], $wo['id']],
        ));
        $this->fail('the requester released its own work order');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42501');
    }
    // nor by a second planner without the approve permission, an approver of another plant, or with a draft BOM
    $release = fn (string $actor, string $bom) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => $db->update(
        "update work_orders set status = 'RELEASED', released_by = ?, released_at = now(), bom_version_id = ?, routing_version_id = ? where id = ?",
        [$actor, $bom, $s['rv']['id'], $wo['id']],
    ));
    $planner = member($this, $this->t);
    AccessFixtures::grant($this->t, $planner['id'], 'PPC');
    $farApprover = member($this, $this->t);
    AccessFixtures::grant($this->t, $farApprover['id'], 'CSA', ['PLANT', $this->t['plants'][1]]);
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    $draftBom = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'insert into bom_versions (tenant_id, item_id, version_no, effective_from, prepared_by) values (?, ?, 99, ?, ?) returning id',
        [$this->t['tenant'], $s['item']['id'], $s['today'], $requester],
    )->id);
    foreach ([[$planner['id'], $s['bom']['id'], '42501'], [$farApprover['id'], $s['bom']['id'], '42501'], [$approver['id'], $draftBom, '23514']] as [$actor, $bom, $code]) {
        try {
            $release($actor, $bom);
            $this->fail("released by {$actor} with {$bom}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe($code);
        }
    }
    // a draft keeps its item's base unit, even when edited through SQL
    try {
        asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update(
            "update work_orders set uom_id = (select id from uoms where code = 'KG') where id = ?", [$wo['id']],
        ));
        $this->fail('the unit of a work order was changed');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
    // nor a fractional quantity of a whole-piece item, a past due date, or Other without a note — through SQL either
    foreach (['qty = 2.5', 'due_date = due_date - 3650', "reason_code = 'OTHER', note = null"] as $change) {
        try {
            asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update("update work_orders set status = 'DRAFT', submitted_by = null, submitted_at = null, {$change} where id = ?", [$wo['id']]));
            $this->fail("accepted: {$change}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23514');
        }
    }
    // nor a release snapshot written outside the release
    try {
        asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update('update work_orders set bom_version_id = ? where id = ?', [$s['bom']['id'], $wo['id']]));
        $this->fail('a snapshot was written outside the release');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
    // cancelling also needs the planner's permission in the WO's plant, in SQL too
    $viewer = member($this, $this->t);
    AccessFixtures::grant($this->t, $viewer['id'], 'FGW');
    try {
        asApp($this->t, $viewer['id'], fn (EoraPostgresConnection $db) => $db->update(
            "update work_orders set status = 'CANCELLED', cancelled_by = ?, cancelled_at = now(), cancel_reason = 'Not mine' where id = ?", [$viewer['id'], $wo['id']],
        ));
        $this->fail('a viewer cancelled the work order');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42501');
    }
    // an approver who releases through SQL without writing the material lines is refused at commit
    try {
        asApp($this->t, $approver['id'], fn (EoraPostgresConnection $db) => $db->update(
            "update work_orders set status = 'RELEASED', released_by = ?, released_at = now(), bom_version_id = ?, routing_version_id = ? where id = ?",
            [$approver['id'], $s['bom']['id'], $s['rv']['id'], $wo['id']],
        ));
        $this->fail('released without its material lines');
    } catch (QueryException|PDOException $e) {
        // the deferred check fires at commit
        expect((string) $e->getCode())->toBe('23514');
    }
    // nor by first replacing who asked, then releasing: the requester is fixed while the request waits
    $other = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select gen_random_uuid() as id')->id);
    try {
        asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update('update work_orders set submitted_by = ? where id = ?', [$other, $wo['id']]));
        $this->fail('the requester was replaced while the request waited');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
    // asking for the release changes nothing of the draft, even in one SQL update (edits need the planner's edit permission)
    asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update(
        "update work_orders set status = 'DRAFT', submitted_by = null, submitted_at = null where id = ?", [$wo['id']],
    ));
    foreach (['qty = qty + 1', 'due_date = due_date + 1', "reason_code = 'SAMPLE_TRIAL'"] as $change) {
        try {
            asApp($this->t, $requester, fn (EoraPostgresConnection $db) => $db->update(
                "update work_orders set status = 'SUBMITTED', submitted_by = ?, submitted_at = now(), {$change} where id = ?", [$requester, $wo['id']],
            ));
            $this->fail("submitted with an edit: {$change}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23514');
        }
    }
    expect($sent['status'])->toBe('SUBMITTED');
})->group('integration', 'planning');

it('checks the draft: finished item, due date from today, a note for Other, a firm delivery of the same item; returns and withdraws', function () {
    $s = woFixture($this, $this->t);
    woCreate($this, $s, ['itemId' => $s['coil']['id']])->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    woCreate($this, $s, ['dueDate' => rtDayFrom($s['today'], -1)])->assertStatus(422)->assertJsonValidationErrors(['dueDate']);
    woCreate($this, $s, ['reasonCode' => 'OTHER'])->assertStatus(422)->assertJsonValidationErrors(['note']);
    woCreate($this, $s, ['qty' => '2.5'])->assertStatus(422)->assertJsonValidationErrors(['qty']);

    // a firm delivery of the item, from a confirmed sales order
    $so = spa($this)->postJson('/api/v1/demand/sales-orders', ['plantId' => $s['plant'], 'customerId' => $s['customer']])->assertCreated()->json('data');
    $so = spa($this)->putJson("/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [['customerItemId' => $s['part'], 'customerPoNo' => 'PO-9', 'customerPoLine' => '1',
        'schedules' => [['requirementDate' => rtDayFrom($s['today'], 7), 'qty' => '80']]]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    $so = spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    $schedule = $so['lines'][0]['schedules'][0]['id'];
    spa($this)->getJson("/api/v1/query/demand.schedule_lines?filter[status]=FIRM&filter[itemId]={$s['item']['id']}")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $schedule)->assertJsonPath('data.0.code', $so['code'])->assertJsonPath('data.0.qty', '80');
    woCreate($this, $s, ['scheduleLineId' => $s['bom']['id']])->assertStatus(422)->assertJsonValidationErrors(['scheduleLineId']);
    // the WO quantity is free against the delivery it serves (D-109)
    $wo = woCreate($this, $s, ['scheduleLineId' => $schedule, 'qty' => '120', 'reasonCode' => 'URGENT_ORDER'])->assertCreated()
        ->assertJsonPath('data.salesOrderCode', $so['code'])->assertJsonPath('data.scheduleQty', '80')->json('data');
    // the delivery a WO serves is of its own item, even when the item is changed through SQL
    $planner = (string) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select created_by from work_orders where id = ?', [$wo['id']])->created_by);
    try {
        asApp($this->t, $planner, fn (EoraPostgresConnection $db) => $db->update(
            'update work_orders set item_id = ?, uom_id = (select base_uom_id from items where id = ?) where id = ?', [$s['coil']['id'], $s['coil']['id'], $wo['id']],
        ));
        $this->fail('a work order serves a delivery of another item');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
    // a delivery cancelled while its WO waits for release: the request still goes back to draft, where the peg is changed
    $pegged = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');
    spa($this)->postJson("/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$schedule}/actions/cancel", ['reason' => 'Customer cancelled'],
        ['If-Match' => '"'.$so['version'].'"'])->assertOk();
    $wo = woAct($this, $wo['id'], 'withdraw', $pegged['version'])->assertOk()->assertJsonPath('data.status', 'DRAFT')->json('data');
    woAct($this, $wo['id'], 'submit', $wo['version'])->assertStatus(422)->assertJsonValidationErrors(['scheduleLineId']);
    $edited = spa($this)->patchJson("/api/v1/planning/work-orders/{$wo['id']}", ['qty' => '150', 'scheduleLineId' => null], ['If-Match' => '"'.$wo['version'].'"'])
        ->assertOk()->assertJsonPath('data.qty', '150')->assertJsonPath('data.salesOrderCode', null)->json('data');

    $sent = woAct($this, $wo['id'], 'submit', $edited['version'])->assertOk()->json('data');
    spa($this)->patchJson("/api/v1/planning/work-orders/{$wo['id']}", ['qty' => '10'], ['If-Match' => '"'.$sent['version'].'"'])->assertStatus(409);
    $back = woAct($this, $wo['id'], 'withdraw', $sent['version'])->assertOk()->assertJsonPath('data.status', 'DRAFT')->json('data');
    $sent = woAct($this, $wo['id'], 'submit', $back['version'])->assertOk()->json('data');
    rtSwitch($this, $this->t, 'CSA');
    woAct($this, $wo['id'], 'withdraw', $sent['version'])->assertForbidden(); // only the requester's side withdraws
    woAct($this, $wo['id'], 'return', $sent['version'], ['reason' => 'x'])->assertStatus(422);
    woAct($this, $wo['id'], 'return', $sent['version'], ['reason' => 'Use the MPS run next week'])->assertOk()
        ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.returnReason', 'Use the MPS run next week');
})->group('integration', 'planning');

it('refuses release without an approved BOM and routing, and cancels drafts and released orders with a reason', function () {
    $s = woFixture($this, $this->t);
    // a finished item without approved BOM and routing
    $newItem = spa($this)->postJson('/api/v1/core/items', ['code' => 'BRK-NEW', 'name' => 'New bracket', 'itemType' => 'FINISHED', 'baseUomId' => $s['item']['baseUomId']])->assertCreated()->json('data');
    $bare = woCreate($this, $s, ['itemId' => $newItem['id']])->assertCreated()->json('data');
    $bareSent = woAct($this, $bare['id'], 'submit', $bare['version'])->assertOk()->json('data');
    $draft = woCreate($this, $s)->assertCreated()->json('data');
    woAct($this, $draft['id'], 'cancel', $draft['version'], ['reason' => 'Duplicate request'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $wo = woCreate($this, $s)->assertCreated()->json('data');
    $sent = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');

    rtSwitch($this, $this->t, 'CSA');
    woAct($this, $bare['id'], 'release', $bareSent['version'])->assertStatus(409)->assertJsonPath('code', 'WO_MASTER_MISSING');
    $released = woAct($this, $wo['id'], 'release', $sent['version'])->assertOk()->json('data');

    rtSwitch($this, $this->t, 'PPC');
    woAct($this, $wo['id'], 'cancel', $released['version'], ['reason' => 'Customer cancelled the urgent order'])->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancelReason', 'Customer cancelled the urgent order');
    woAct($this, $wo['id'], 'cancel', $released['version'] + 1, ['reason' => 'Again'])->assertStatus(409);
    // the material snapshot gets no line after its release, not even through SQL
    try {
        AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
            'insert into work_order_materials (tenant_id, work_order_id, line_no, bom_line_id, component_item_id, uom_id, gross_qty_per, scrap_allowance, required_qty)
             select tenant_id, work_order_id, 99, bom_line_id, component_item_id, uom_id, gross_qty_per, scrap_allowance, 999 from work_order_materials where work_order_id = ? limit 1',
            [$wo['id']],
        ));
        $this->fail('a material line was added to a released snapshot');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
})->group('integration', 'planning');

it('keeps work orders inside the plant scope', function () {
    $s = woFixture($this, $this->t);
    $wo = woCreate($this, $s)->assertCreated()->json('data');
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][1]]);
    spa($this)->getJson("/api/v1/planning/work-orders/{$wo['id']}")->assertNotFound();
    spa($this)->getJson('/api/v1/query/planning.work_orders')->assertOk()->assertJsonCount(0, 'data');
    woCreate($this, $s)->assertForbidden();
})->group('integration', 'planning');

it('reads the plant calendar date in the database exactly as the business calendar does (time zone of the calendar in force)', function () {
    woFixture($this, $this->t);
    $plant = $this->t['plants'][1];
    // the plant's own time zone differs from the tenant calendar in force (Asia/Jakarta): the calendar's zone decides
    foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Jakarta'] as $zone) {
        AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update plants set timezone = ? where id = ?', [$zone, $plant]));
        $both = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => [
            (string) $db->selectOne('select eora_plant_calendar_date(?)::text as d', [$plant])->d,
            app(BusinessCalendar::class)->resolve($db, $plant, new DateTimeImmutable)->calendarDate,
        ]);
        expect($both[0])->toBe($both[1]);
    }
})->group('integration', 'planning');

<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 3 (doc 33 §1.3, D-110, doc 04 rows 3 and 6, BR-ISS-01/02/04/05, doc 14 #1 and #10): materials issues a released
 * WO's material lines to a work center of its routing (no consumption); production asks for a return, materials receives
 * it as measured or refuses it; issues and returns are reversed by the M3 reversal flow.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * A released WO of 100 pieces (coil 50 KG lot-tracked, nut 200 PCS not lot-tracked), a raw-material rack with 80 KG of one coil lot and
 * 300 nuts received by materials. Ends signed in as that MAT user.
 *
 * @return array<string, mixed>
 */
function mfFixture(object $test, array $t): array
{
    $s = woFixture($test, $t);
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update items set lot_controlled = (id = ?) where id in (?, ?)', [$s['coil']['id'], $s['coil']['id'], $s['nut']['id']]));
    $wo = woCreate($test, $s)->assertCreated()->json('data');
    $sent = woAct($test, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');
    rtSwitch($test, $t, 'CSA');
    $wo = woAct($test, $wo['id'], 'release', $sent['version'])->assertOk()->json('data');
    [$rack, $rack2, $supplier] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $s) {
        $wh = $db->selectOne("insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values (?, ?, 'RM', 'Raw', 'RAW_MATERIAL') returning id", [$t['tenant'], $s['plant']])->id;
        $rack = fn (string $code) => (string) $db->selectOne("insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, ?, ?, 'RM_WAREHOUSE') returning id",
            [$t['tenant'], $s['plant'], $wh, $code, "Rack {$code}"])->id;

        return [$rack('RM-01'), $rack('RM-02'), (string) $db->selectOne("insert into suppliers (tenant_id, code, name) values (?, 'SUP-1', 'Steel') returning id", [$t['tenant']])->id];
    });
    $mat = rtSwitch($test, $t, 'MAT');
    $lot = spa($test)->postJson('/api/v1/inventory/receipts', ['plantId' => $s['plant'], 'itemId' => $s['coil']['id'], 'locationId' => $rack, 'supplierId' => $supplier,
        'qty' => '80', 'supplierLotNo' => 'HEAT-1'], ['Idempotency-Key' => 'mf-receipt-coil'])->assertCreated()->json('data.lotId');
    spa($test)->postJson('/api/v1/inventory/receipts', ['plantId' => $s['plant'], 'itemId' => $s['nut']['id'], 'locationId' => $rack, 'supplierId' => $supplier, 'qty' => '300'],
        ['Idempotency-Key' => 'mf-receipt-nut'])->assertCreated();

    return $s + ['wo' => $wo, 'rack' => $rack, 'rack2' => $rack2, 'supplier' => $supplier, 'lot' => $lot, 'mat' => $mat];
}

function mfIssue(object $test, array $f, array $body = [], string $key = 'mf-issue-0001')
{
    return spa($test)->postJson('/api/v1/inventory/material-issues', $body + [
        'workOrderId' => $f['wo']['id'], 'workCenterId' => $f['wc']['id'], 'lines' => [
            ['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '60'],
            ['itemId' => $f['nut']['id'], 'fromLocationId' => $f['rack'], 'qty' => '200'],
        ],
    ], ['Idempotency-Key' => $key]);
}

function mfAsk(object $test, array $f, array $body = [], string $key = 'mf-return-0001')
{
    return spa($test)->postJson('/api/v1/inventory/material-returns', $body + [
        'workOrderId' => $f['wo']['id'], 'workCenterId' => $f['wc']['id'], 'itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'qty' => '15', 'note' => 'End of coil',
    ], ['Idempotency-Key' => $key]);
}

/** @return array<string, string> location code => quantity of the coil lot */
function mfCoil(array $t, array $f): array
{
    return AccessFixtures::run($t, fn (EoraPostgresConnection $db) => array_column($db->select(
        'select l.code, trim_scale(b.qty)::text as q from stock_balances b join locations l on l.id = b.location_id where b.item_id = ? and b.qty <> 0 order by l.code',
        [$f['coil']['id']],
    ), 'q', 'code'));
}

it('issues a released WO\'s materials to a work center of its routing without consuming them, and returns them as measured', function () {
    $f = mfFixture($this, $this->t);
    $year = substr($f['today'], 0, 4);
    // over-issue is allowed (a whole coil): 60 KG against 50 required (D-110)
    $issued = mfIssue($this, $f)->assertCreated()->assertJsonPath('data.docNo', "ISS-P1-{$year}-0001")->json('data');
    mfIssue($this, $f)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.documentId', $issued['documentId']);
    // a retry replays even after the work center's location was deactivated; a new issue there is refused
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update locations set status = 'INACTIVE' where code = 'MAP-PRS-01'"));
    mfIssue($this, $f)->assertOk()->assertJsonPath('data.documentId', $issued['documentId']);
    mfIssue($this, $f, [], 'mf-issue-0010')->assertStatus(422)->assertJsonValidationErrors(['workCenterId']);
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update locations set status = 'ACTIVE' where code = 'MAP-PRS-01'"));
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '60', 'RM-01' => '20']);
    // the stock list shows material at a work center with its work center, not a warehouse (BR-INV-01)
    $rows = collect(spa($this)->getJson('/api/v1/query/inventory.stock?filter[itemId]='.$f['coil']['id'])->assertOk()->json('data'))->keyBy('locationCode');
    expect($rows['MAP-PRS-01'])->workCenterCode->toBe('PRS-01')->warehouseCode->toBeNull()->qty->toBe('60')
        ->and($rows['RM-01'])->warehouseCode->toBe('RM')->workCenterCode->toBeNull();
    // doc 14 #1: an issue is not a consumption
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne("select count(*) as n from inventory_movements where movement_code = 'CONSUME'")->n))->toBe(0);
    spa($this)->getJson('/api/v1/query/materials.issues')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.workOrderCode', $f['wo']['code'])->assertJsonPath('data.0.workCenterCode', 'PRS-01')->assertJsonPath('data.0.lines', 2);
    spa($this)->getJson('/api/v1/query/inventory.material_at_production?sort=itemCode')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.itemCode', 'COIL-9')->assertJsonPath('data.0.qty', '60')->assertJsonPath('data.0.workOrderCode', $f['wo']['code']);

    // what is not issued: a work center outside the routing, a material outside the WO's lines, a lot the item does not have
    $other = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into work_centers (tenant_id, plant_id, code, name, work_center_type) values (?, ?, 'PNT-01', 'Paint', 'OTHER') returning id", [$this->t['tenant'], $f['plant']],
    )->id);
    mfIssue($this, $f, ['workCenterId' => $other], 'mf-issue-0002')->assertStatus(422)->assertJsonValidationErrors(['workCenterId']);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['item']['id'], 'fromLocationId' => $f['rack'], 'qty' => '1']]], 'mf-issue-0003')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.itemId']);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'fromLocationId' => $f['rack'], 'qty' => '1']]], 'mf-issue-0004')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.lotId']);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '21']]], 'mf-issue-0005')
        ->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    // the WO's page shows what was issued and what is still at the work center
    rtSwitch($this, $this->t, 'PPC');
    $wo = spa($this)->getJson("/api/v1/planning/work-orders/{$f['wo']['id']}")->assertOk()->json('data');
    $materials = $wo['materials'];
    // doc 16: a released WO with posted issues is not cancelled
    woAct($this, $f['wo']['id'], 'cancel', $wo['version'], ['reason' => 'Order dropped'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(array_map(fn ($m) => [$m['itemCode'], $m['requiredQty'], $m['issuedQty'], $m['atProductionQty'], $m['baseUomCode']], $materials))
        ->toBe([['COIL-9', '50', '60', '60', 'KG'], ['NUT-9', '200', '200', '200', 'PCS']]);

    // production asks for a return; nothing moves until materials receives it
    $prl = rtSwitch($this, $this->t, 'PRL');
    mfIssue($this, $f, [], 'mf-issue-0006')->assertForbidden();
    mfAsk($this, $f, ['qty' => '61'], 'mf-return-0009')->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $asked = mfAsk($this, $f)->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data');
    mfAsk($this, $f)->assertOk()->assertJsonPath('data.documentId', $asked['documentId']);
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '60', 'RM-01' => '20']);
    // who asked never receives it (no receive permission either)
    spa($this)->postJson("/api/v1/inventory/material-returns/{$asked['documentId']}/receive", ['toLocationId' => $f['rack2'], 'qty' => '15'], ['Idempotency-Key' => 'mf-receive-0001'])
        ->assertForbidden();

    // materials receives what it weighed (14.5 of the 15 asked) into a rack of its choice; both quantities are kept
    signIn($this, $f['mat'], $this->t);
    $received = spa($this)->postJson("/api/v1/inventory/material-returns/{$asked['documentId']}/receive", ['toLocationId' => $f['rack2'], 'qty' => '14.5'], ['Idempotency-Key' => 'mf-receive-0002'])
        ->assertCreated()->assertJsonPath('data.docNo', "RTN-P1-{$year}-0001")->json('data');
    spa($this)->postJson("/api/v1/inventory/material-returns/{$asked['documentId']}/receive", ['toLocationId' => $f['rack2'], 'qty' => '14.5'], ['Idempotency-Key' => 'mf-receive-0002'])
        ->assertOk()->assertJsonPath('data.documentId', $received['documentId']);
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '45.5', 'RM-01' => '20', 'RM-02' => '14.5']);
    spa($this)->getJson('/api/v1/query/materials.returns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'POSTED')
        ->assertJsonPath('data.0.askedQty', '15')->assertJsonPath('data.0.receivedQty', '14.5')->assertJsonPath('data.0.differenceQty', '-0.5')
        ->assertJsonPath('data.0.toLocationCode', 'RM-02')->assertJsonPath('data.0.workCenterCode', 'PRS-01');
    spa($this)->postJson("/api/v1/inventory/material-returns/{$asked['documentId']}/refuse", ['reason' => 'Too late'])->assertStatus(409);

    // a second return is refused with the reason; nothing moves
    signIn($this, $prl, $this->t);
    $second = mfAsk($this, $f, ['qty' => '5'], 'mf-return-0002')->assertCreated()->json('data.documentId');
    signIn($this, $f['mat'], $this->t);
    spa($this)->postJson("/api/v1/inventory/material-returns/{$second}/refuse", ['reason' => 'Coil end is still in use'])->assertNoContent();
    spa($this)->postJson("/api/v1/inventory/material-returns/{$second}/refuse", ['reason' => 'Coil end is still in use'])->assertNoContent();
    spa($this)->getJson("/api/v1/query/materials.returns?filter[id]={$second}")->assertOk()->assertJsonPath('data.0.status', 'REJECTED')
        ->assertJsonPath('data.0.rejectReason', 'Coil end is still in use')->assertJsonPath('data.0.receivedQty', null);
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '45.5', 'RM-01' => '20', 'RM-02' => '14.5']);
})->group('integration', 'inventory');

it('reverses an issue only while its material is still at the work center (doc 04 row 3)', function () {
    $f = mfFixture($this, $this->t);
    $issue = mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '10']]])->assertCreated()->json('data.documentId');
    $prl = rtSwitch($this, $this->t, 'PRL');
    $asked = mfAsk($this, $f, ['qty' => '10'])->assertCreated()->json('data.documentId');
    signIn($this, $f['mat'], $this->t);
    $return = spa($this)->postJson("/api/v1/inventory/material-returns/{$asked}/receive", ['toLocationId' => $f['rack'], 'qty' => '10'], ['Idempotency-Key' => 'mf-receive-0001'])
        ->assertCreated()->json('data.documentId');

    // doc 16: the WO is not cancelled while an issue is posted for it, straight as SQL either (the API in the first test)
    try {
        asApp($this->t, (string) $f['mat']['id'], fn (EoraPostgresConnection $db) => $db->update(
            "update work_orders set status = 'CANCELLED', cancelled_by = ?, cancelled_at = now(), cancel_reason = 'Order dropped' where id = ?", [$f['mat']['id'], $f['wo']['id']],
        ));
        $this->fail('a work order with a posted issue was cancelled');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }

    rtSwitch($this, $this->t, 'INV');
    $rvIssue = rvRequest($this, $issue)->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    // the material went back: the issue is reversed after its return
    rvApprove($this, $rvIssue)->assertStatus(409)->assertJsonPath('code', 'REVERSAL_BLOCKED_BY_DOWNSTREAM');
    spa($this)->postJson("/api/v1/inventory/reversals/{$rvIssue}/reject", ['reason' => 'Reverse the return first'])->assertNoContent();
    rtSwitch($this, $this->t, 'INV');
    $rvReturn = rvRequest($this, $return, 'reverse-key-0002')->assertCreated()->json('data.documentId');
    $rvIssue = rvRequest($this, $issue, 'reverse-key-0003')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    rvApprove($this, $rvReturn)->assertCreated();
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '10', 'RM-01' => '70']);
    rvApprove($this, $rvIssue, 'reverse-approve-0002')->assertCreated();
    expect(mfCoil($this->t, $f))->toBe(['RM-01' => '80']);
    // its issue reversed, the WO's cancellation is no longer refused for it (the trigger lets it through to its own checks)
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) as n from inventory_documents where doc_type = 'MAT_ISSUE' and status = 'POSTED' and source_id = ?", [$f['wo']['id']],
    )->n))->toBe(0);
    expect($prl)->toBeArray();
})->group('integration', 'inventory');

it('keeps each work order\'s material apart at a shared work center, in the database too (doc 14 #10)', function () {
    $f = mfFixture($this, $this->t);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '10']]])->assertCreated();
    // a second released WO of the same item at the same work center gets 5 KG of the same lot
    rtSwitch($this, $this->t, 'PPC');
    $wo2 = woCreate($this, $f)->assertCreated()->json('data');
    $sent = woAct($this, $wo2['id'], 'submit', $wo2['version'])->assertOk()->json('data');
    rtSwitch($this, $this->t, 'CSA');
    $wo2 = woAct($this, $wo2['id'], 'release', $sent['version'])->assertOk()->json('data');
    signIn($this, $f['mat'], $this->t);
    $g = ['wo' => $wo2] + $f;
    mfIssue($this, $g, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '5']]], 'mf-issue-0002')->assertCreated();
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '15', 'RM-01' => '65']);

    // the second WO cannot give back the first WO's material, though the balance at the work center holds it
    rtSwitch($this, $this->t, 'PRL');
    mfAsk($this, $g, ['qty' => '6'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $asked = mfAsk($this, $g, ['qty' => '5'])->assertCreated()->json('data.documentId');
    signIn($this, $f['mat'], $this->t);
    spa($this)->postJson("/api/v1/inventory/material-returns/{$asked}/receive", ['toLocationId' => $f['rack'], 'qty' => '6'], ['Idempotency-Key' => 'mf-receive-0001'])
        ->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    // straight to the posting service (past the HTTP checks), the database still refuses what breaks the rules
    $map = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'MAP-PRS-01'")->id);
    $map2 = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'MAP-WLD-01'")->id);
    $post = fn (string $actor, PostingCommand $c) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db, $c));
    $leg = fn (string $loc, string $qty, ?string $wo, string $item = 'coil') => new PostingLeg('ISSUE_TO_PROD', $loc, $f[$item]['id'], $item === 'coil' ? $f['lot'] : null, 'UNRESTRICTED', $qty, woId: $wo);
    $issue = fn (array $legs, string $wo, string $key) => new PostingCommand('MAT_ISSUE', $f['plant'], $key, $legs, sourceType: 'work_order', sourceId: $wo);
    $refused = function (Closure $attempt, string $why) {
        try {
            $attempt();
            $this->fail("posted: {$why}");
        } catch (DomainProblem $e) {
            expect($e->errorCode)->toBe('POSTING_REFUSED', $why);
        } catch (QueryException $e) {
            expect($e->getCode())->toBeIn(['23514', '42501'], $why);
        }
    };
    $mat = (string) $f['mat']['id'];
    // into the second operation's work center the issue is allowed (it is on the routing); a WO's legs name that WO
    $refused(fn () => $post($mat, $issue([$leg($f['rack'], '-1', $f['wo']['id']), $leg($map, '1', $wo2['id'])], $f['wo']['id'], 'mf-direct-0001')), 'a leg of another WO');
    $refused(fn () => $post($mat, $issue([$leg($f['rack'], '-1', $f['wo']['id']), $leg($map, '1', $f['wo']['id']), $leg($f['rack'], '-1', $f['wo']['id']), $leg($map2, '1', $f['wo']['id'])],
        $f['wo']['id'], 'mf-direct-0002')), 'two work centers in one issue');
    // a planner (no issue permission) is refused by the database as well
    $ppc = (string) rtSwitch($this, $this->t, 'PPC')['id'];
    $refused(fn () => $post($ppc, $issue([$leg($f['rack'], '-1', $f['wo']['id']), $leg($map, '1', $f['wo']['id'])], $f['wo']['id'], 'mf-direct-0003')), 'issued by a planner');
    // a transfer never moves stock into Material at Production without its WO
    $refused(fn () => $post($mat, new PostingCommand('MAT_TRANSFER', $f['plant'], 'mf-direct-0004', [
        new PostingLeg('TRANSFER_LOC', $map, $f['coil']['id'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('TRANSFER_LOC', $map2, $f['coil']['id'], $f['lot'], 'UNRESTRICTED', '1'),
    ])), 'a transfer between work centers without a WO');
    // a return keeps what was asked, written straight as SQL either
    $refused(fn () => asApp($this->t, $mat, function (EoraPostgresConnection $db) use ($wo2) {
        $db->update("update inventory_documents set note = 'x' where source_id = ? and doc_type = 'MAT_RETURN'", [$wo2['id']]);
    }), 'a return request changed after it was asked');
    expect(mfCoil($this->t, $f))->toBe(['MAP-PRS-01' => '15', 'RM-01' => '65']);
})->group('integration', 'inventory');

it('keeps the request hash of a posting without a work order as it was before legs named one (ADR-0014)', function () {
    $leg = new PostingLeg('TRANSFER_LOC', 'a', 'b', null, 'UNRESTRICTED', '4');
    $before = ['TRANSFER_LOC', 'a', 'b', null, 'UNRESTRICTED', '4', null, null, null, null];
    expect($leg->hashShape())->toBe($before)
        ->and((new PostingLeg('ISSUE_TO_PROD', 'a', 'b', null, 'UNRESTRICTED', '4', woId: 'w'))->hashShape())->toBe(['ISSUE_TO_PROD', ...array_slice($before, 1), 'w']);
    $command = new PostingCommand('MAT_TRANSFER', 'p', 'transfer-key-0001', [$leg]);
    expect($command->hash())->toBe(hash('sha256', json_encode(['MAT_TRANSFER', 'p', null, null, null, null, [$before], []], JSON_THROW_ON_ERROR)));
})->group('inventory');

it('splits a lot at a work center for its work order, keeping that work order on every lot (doc 14 #22)', function () {
    $f = mfFixture($this, $this->t);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '30']]])->assertCreated();
    $map = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'MAP-PRS-01'")->id);
    rtSwitch($this, $this->t, 'PRL');
    $split = fn (array $body, string $key) => spa($this)->postJson('/api/v1/inventory/lot-splits', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'stockStatus' => 'UNRESTRICTED', 'locationId' => $map,
        'children' => [['qty' => '5', 'widthMm' => '120']],
    ], ['Idempotency-Key' => $key]);
    // whose material is split must be said at a work center, and only there
    $split([], 'mf-split-0001')->assertStatus(422)->assertJsonValidationErrors(['workOrderId']);
    $split(['workOrderId' => $f['wo']['id'], 'locationId' => $f['rack']], 'mf-split-0002')->assertStatus(422)->assertJsonValidationErrors(['workOrderId']);
    // no more than the work order has there
    $split(['workOrderId' => $f['wo']['id'], 'children' => [['qty' => '31']]], 'mf-split-0003')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    $child = $split(['workOrderId' => $f['wo']['id']], 'mf-split-0004')->assertCreated()->json('data.children.0');
    $split(['workOrderId' => $f['wo']['id']], 'mf-split-0004')->assertOk()->assertJsonPath('data.children.0.lotId', $child['lotId']);
    $rows = collect(spa($this)->getJson("/api/v1/query/inventory.material_at_production?filter[workOrderId]={$f['wo']['id']}")->assertOk()->json('data'))->keyBy('lotNo');
    expect($rows)->toHaveCount(2)
        ->and($rows[$child['lotNo']]['qty'])->toBe('5')
        ->and($rows->first(fn ($r) => $r['lotId'] === $f['lot'])['qty'])->toBe('25');
})->group('integration', 'inventory');

it('adjusts material at a work center for its work order, within what that order has there (doc 04 row 12)', function () {
    $f = mfFixture($this, $this->t);
    mfIssue($this, $f, ['lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '30']]])->assertCreated();
    $map = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'MAP-PRS-01'")->id);
    // material at a work center leaves by a return, not a transfer (M4-D3)
    $map2 = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'MAP-WLD-01'")->id);
    spa($this)->postJson('/api/v1/inventory/transfers', ['plantId' => $f['plant'], 'itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'stockStatus' => 'UNRESTRICTED',
        'fromLocationId' => $map, 'toLocationId' => $map2, 'qty' => '1'], ['Idempotency-Key' => 'mf-transfer-0001'])->assertStatus(422)->assertJsonValidationErrors(['fromLocationId']);
    rtSwitch($this, $this->t, 'INV');
    $ask = fn (array $body, string $key) => spa($this)->postJson('/api/v1/inventory/adjustments', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'locationId' => $map, 'stockStatus' => 'UNRESTRICTED',
        'direction' => 'MINUS', 'qty' => '2', 'reasonCode' => 'COUNT_CORRECTION', 'note' => 'Weighed at the press',
    ], ['Idempotency-Key' => $key]);
    $ask([], 'mf-adjust-0001')->assertStatus(422)->assertJsonValidationErrors(['workOrderId']);
    $ask(['workOrderId' => $f['wo']['id'], 'direction' => 'PLUS', 'stockStatus' => 'BLOCKED'], 'mf-adjust-0005')->assertStatus(422)->assertJsonValidationErrors(['stockStatus']);
    $ask(['workOrderId' => $f['wo']['id'], 'qty' => '31'], 'mf-adjust-0002')->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $id = $ask(['workOrderId' => $f['wo']['id']], 'mf-adjust-0003')->assertCreated()->json('data.documentId');
    // found material at a work center is added for the chosen WO, whatever that order has recorded there
    $ask(['workOrderId' => $f['wo']['id'], 'direction' => 'PLUS', 'itemId' => $f['nut']['id'], 'lotId' => null, 'reasonCode' => 'FOUND'], 'mf-adjust-0004')->assertCreated();
    spa($this)->getJson("/api/v1/query/inventory.adjustments?filter[id]={$id}")->assertOk()->assertJsonPath('data.0.workOrderCode', $f['wo']['code']);
    rtSwitch($this, $this->t, 'CSA');
    adApprove($this, $id)->assertCreated();
    expect(spa($this)->getJson("/api/v1/query/inventory.material_at_production?filter[workOrderId]={$f['wo']['id']}")->assertOk()->json('data.0.qty'))->toBe('28');
})->group('integration', 'inventory');

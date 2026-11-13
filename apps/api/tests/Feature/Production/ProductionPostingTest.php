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
 * M4 step 4 (doc 33 §1.4, D-111, doc 04 rows 5 and 8, ADR-0003, BR-CON-03/05, BR-OUT-01..04/08, doc 14 #19, #20, #26):
 * a production leader opens a run for a released WO at a work center, posts measured consumption, output by category
 * and scrap in one document, and closes the run.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * mfFixture plus 60 KG of coil and 200 nuts issued to PRS-01, a QC area, NG area and scrap yard, and a scrap item.
 * Ends signed in as a production leader.
 *
 * @return array<string, mixed>
 */
function prFixture(object $test, array $t): array
{
    $f = mfFixture($test, $t);
    mfIssue($test, $f)->assertCreated();
    [$qc, $ng, $yard, $scrapItem, $map] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $wh = (string) $db->selectOne("select id from warehouses where plant_id = ? and code = 'RM'", [$f['plant']])->id;
        $loc = fn (string $code, string $type) => (string) $db->selectOne(
            'insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, ?, ?, ?) returning id',
            [$t['tenant'], $f['plant'], $wh, $code, "Area {$code}", $type],
        )->id;
        $kg = (string) $db->selectOne("select id from uoms where code = 'KG'")->id;

        return [$loc('QC-01', 'QC_AREA'), $loc('NG-01', 'NG_AREA'), $loc('SCR-01', 'SCRAP_YARD'),
            (string) $db->selectOne("insert into items (tenant_id, code, name, item_type, base_uom_id, lot_controlled) values (?, 'SCRAP-SPCC', 'SPCC scrap', 'SCRAP', ?, false) returning id", [$t['tenant'], $kg])->id,
            (string) $db->selectOne("select id from locations where code = 'MAP-PRS-01'")->id];
    });
    $prl = rtSwitch($test, $t, 'PRL');

    return $f + compact('qc', 'ng', 'yard', 'scrapItem', 'map', 'prl');
}

function prOpen(object $test, array $f, array $body = [])
{
    return spa($test)->postJson('/api/v1/production/runs', $body + ['workOrderId' => $f['wo']['id'], 'workCenterId' => $f['wc']['id']]);
}

function prPost(object $test, array $f, string $run, array $body = [], string $key = 'pr-post-0001')
{
    return spa($test)->postJson('/api/v1/production/postings', $body + [
        'runId' => $run,
        'consumptions' => [
            ['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_REMAINING', 'qty' => '12', 'evidence' => 'Scale S-01'],
            ['itemId' => $f['nut']['id'], 'method' => 'COUNTED_REMAINING', 'qty' => '0', 'evidence' => 'Counted by hand'],
        ],
        'goodQty' => '90', 'ngReworkQty' => '5', 'ngRejectQty' => '3',
        'scrap' => [['itemId' => $f['scrapItem'], 'qty' => '6', 'reasonCode' => 'SKELETON']],
    ], ['Idempotency-Key' => $key]);
}

/** @return array<string, string> "location code / status" => quantity of the item */
function prStock(array $t, string $item): array
{
    return AccessFixtures::run($t, fn (EoraPostgresConnection $db) => array_column($db->select(
        "select l.code || ' / ' || b.stock_status as k, trim_scale(b.qty)::text as q from stock_balances b join locations l on l.id = b.location_id where b.item_id = ? and b.qty <> 0 order by 1",
        [$item],
    ), 'q', 'k'));
}

it('opens a run, posts measured consumption, output by category and scrap in one document, and closes the run', function () {
    $f = prFixture($this, $this->t);
    $year = substr($f['today'], 0, 4);
    $other = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into work_centers (tenant_id, plant_id, code, name, work_center_type) values (?, ?, 'PNT-01', 'Paint', 'OTHER') returning id", [$this->t['tenant'], $f['plant']],
    )->id);
    prOpen($this, $f, ['workCenterId' => $other])->assertStatus(422)->assertJsonValidationErrors(['workCenterId']);
    $run = prOpen($this, $f)->assertCreated()->assertJsonPath('data.code', "RUN-P1-{$year}-0001")->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.workOrderStatus', 'IN_PROGRESS')->json('data');
    expect(array_map(fn ($m) => [$m['itemCode'], $m['qty']], $run['atWorkCenter']))->toBe([['COIL-9', '60'], ['NUT-9', '200']]);
    // a run with nothing posted is not closed
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/close", [], ['If-Match' => '"'.$run['version'].'"'])->assertStatus(409);

    // ADR-0003: what is left is measured; consumed = what the WO had there − what is left (doc 14 #26: never more)
    prPost($this, $f, $run['id'], ['consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_REMAINING', 'qty' => '61', 'evidence' => 'S-01']]], 'pr-post-0009')
        ->assertStatus(422)->assertJsonValidationErrors(['consumptions.0.qty']);
    prPost($this, $f, $run['id'], ['consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '61', 'evidence' => 'S-01']]], 'pr-post-0008')
        ->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    prPost($this, $f, $run['id'], ['goodQty' => null, 'ngReworkQty' => null, 'ngRejectQty' => null, 'scrap' => []], 'pr-post-0007')
        ->assertStatus(422)->assertJsonValidationErrors(['goodQty']);

    $posted = prPost($this, $f, $run['id'])->assertCreated()->assertJsonPath('data.docNo', "PRD-P1-{$year}-0001")->json('data');
    prPost($this, $f, $run['id'])->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.documentId', $posted['documentId']);
    expect($posted['lotNo'])->toStartWith('L');
    // BR-OUT-08: one regular posting per run, production date and shift
    prPost($this, $f, $run['id'], ['consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '1', 'evidence' => 'S-01']],
        'goodQty' => '1', 'ngReworkQty' => '0', 'ngRejectQty' => '0', 'scrap' => []], 'pr-post-0002')
        ->assertStatus(409)->assertJsonPath('code', 'PRODUCTION_ALREADY_POSTED');

    // material: 48 KG coil and 200 nuts consumed, 12 KG coil left at the work center; no backflush (BR-CON-05)
    expect(prStock($this->t, $f['coil']['id']))->toBe(['MAP-PRS-01 / UNRESTRICTED' => '12', 'RM-01 / UNRESTRICTED' => '20'])
        ->and(prStock($this->t, $f['nut']['id']))->toBe(['RM-01 / UNRESTRICTED' => '100'])
        // output by category (BR-OUT-02/03), not inspected → QC released (M4-D6); scrap in kg (BR-OUT-04)
        ->and(prStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '3', 'NG-01 / REWORK' => '5', 'QC-01 / QC_RELEASED' => '90'])
        ->and(prStock($this->t, $f['scrapItem']))->toBe(['SCR-01 / UNRESTRICTED' => '6']);
    // genealogy: each consumed lot into the output lot (BR-OUT-01, doc 14 #19); the nut is not lot-tracked, so it has none
    $edges = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        'select trim_scale(g.qty_from)::text as q, trim_scale(g.qty_to)::text as out, l.lot_no from genealogy_edges g join lots l on l.id = g.to_lot_id where g.document_id = ? order by g.qty_from',
        [$posted['documentId']],
    ));
    expect(array_map(fn ($e) => [$e->q, $e->out, $e->lot_no], $edges))->toBe([['48', '98', $posted['lotNo']]]);
    $measure = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select measurement_method, trim_scale(measured_qty)::text as measured, measurement_evidence from inventory_movements where document_id = ? and movement_code = 'CONSUME' and qty_signed < 0 and item_id = ?",
        [$posted['documentId'], $f['coil']['id']],
    ));
    expect([$measure->measurement_method, $measure->measured, $measure->measurement_evidence])->toBe(['WEIGHED_REMAINING', '12', 'Scale S-01']);

    spa($this)->getJson('/api/v1/query/production.runs?sort=workCenterCode')->assertOk();
    spa($this)->getJson('/api/v1/query/production.runs?sort=-itemCode')->assertOk();
    spa($this)->getJson("/api/v1/query/production.postings?filter[runId]={$run['id']}")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.goodQty', '90')->assertJsonPath('data.0.ngReworkQty', '5')->assertJsonPath('data.0.ngRejectQty', '3')->assertJsonPath('data.0.scrapSummary', '6 KG')
        ->assertJsonPath('data.0.outputLotNo', $posted['lotNo']);
    $run = spa($this)->getJson("/api/v1/production/runs/{$run['id']}")->assertOk()->assertJsonPath('data.goodQty', '90')->assertJsonPath('data.postings', 1)->json('data');
    expect(array_map(fn ($m) => [$m['itemCode'], $m['qty']], $run['atWorkCenter']))->toBe([['COIL-9', '12']]);

    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/close", [], ['If-Match' => '"'.$run['version'].'"'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    prPost($this, $f, $run['id'], [], 'pr-post-0003')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // a closed run takes only the correction of a reversed posting; the inspection rule may change in between
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update items set inspection_required = true where id = ?', [$f['item']['id']]));
    rtSwitch($this, $this->t, 'INV');
    $rv = rvRequest($this, $posted['documentId'], 'pr-reverse-0001')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    rvApprove($this, $rv, 'pr-reverse-approve-0001')->assertCreated();
    signIn($this, $f['prl'], $this->t);
    prPost($this, $f, $run['id'], ['correctsPostingId' => $posted['documentId'], 'consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '40', 'evidence' => 'S-01']],
        'scrap' => []], 'pr-post-0005')->assertCreated();
    expect(prStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '3', 'NG-01 / REWORK' => '5', 'QC-01 / PENDING_QC' => '90']);
})->group('integration', 'production');

it('cancels a run without postings, and corrects a reversed posting with a new one (BR-OUT-08)', function () {
    $f = prFixture($this, $this->t);
    $first = prOpen($this, $f)->assertCreated()->json('data');
    spa($this)->postJson("/api/v1/production/runs/{$first['id']}/actions/cancel", ['reason' => 'Wrong machine'], ['If-Match' => '"'.$first['version'].'"'])
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancelReason', 'Wrong machine');
    $run = prOpen($this, $f)->assertCreated()->json('data');
    $posted = prPost($this, $f, $run['id'])->assertCreated()->json('data');
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/cancel", ['reason' => 'Too late'], ['If-Match' => '"'.$run['version'].'"'])->assertStatus(409);

    // reversed through the M3 flow (D-111), then posted again as a correction naming it
    rtSwitch($this, $this->t, 'INV');
    $rv = rvRequest($this, $posted['documentId'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    rvApprove($this, $rv)->assertCreated();
    expect(prStock($this->t, $f['item']['id']))->toBe([])->and(prStock($this->t, $f['coil']['id']))->toBe(['MAP-PRS-01 / UNRESTRICTED' => '60', 'RM-01 / UNRESTRICTED' => '20']);
    signIn($this, $f['prl'], $this->t);
    prPost($this, $f, $run['id'], [], 'pr-post-0002')->assertStatus(409)->assertJsonPath('code', 'PRODUCTION_ALREADY_POSTED');
    prPost($this, $f, $run['id'], ['correctsPostingId' => $posted['documentId'], 'goodQty' => '92', 'ngReworkQty' => '3'], 'pr-post-0003')
        ->assertCreated();
    expect(prStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '3', 'NG-01 / REWORK' => '3', 'QC-01 / QC_RELEASED' => '92']);
    // a reversed posting is corrected once (a second correction would be a second regular posting in disguise)
    prPost($this, $f, $run['id'], ['correctsPostingId' => $posted['documentId'], 'consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '1', 'evidence' => 'S-01']],
        'goodQty' => '1', 'ngReworkQty' => null, 'ngRejectQty' => null, 'scrap' => []], 'pr-post-0004')
        ->assertStatus(409)->assertJsonPath('code', 'PRODUCTION_ALREADY_POSTED');
    spa($this)->getJson("/api/v1/query/production.postings?filter[runId]={$run['id']}&sort=postedAt")->assertOk()->assertJsonPath('data.1.correctsCode', $posted['docNo']);
})->group('integration', 'production');

it('refuses in the database what breaks the production rules', function () {
    $f = prFixture($this, $this->t);
    $run = prOpen($this, $f)->assertCreated()->json('data');
    // a planner or operator does not open runs
    rtSwitch($this, $this->t, 'OPR');
    prOpen($this, $f)->assertForbidden();
    $ids = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => [
        'consumed' => (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_CONSUMED'", [$f['plant']])->id,
        'produced' => (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_PRODUCED'", [$f['plant']])->id,
    ]);
    $post = fn (string $actor, array $legs, string $key) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db,
        new PostingCommand('PRODUCTION_POSTING', $f['plant'], $key, $legs, sourceType: 'production_run', sourceId: $run['id'])));
    $consume = fn (string $qty = '1', ?string $wo = null, string $method = 'WEIGHED_CONSUMED', string $measured = '1') => [
        new PostingLeg('CONSUME', $f['map'], $f['coil']['id'], $f['lot'], 'UNRESTRICTED', '-'.$qty, woId: $wo ?? $f['wo']['id'], measurementMethod: $method, measuredQty: $measured, measurementEvidence: 'S-01'),
        new PostingLeg('CONSUME', $ids['consumed'], $f['coil']['id'], $f['lot'], 'UNRESTRICTED', $qty),
    ];
    $output = fn (string $item, string $status = 'QC_RELEASED', ?string $to = null) => [
        new PostingLeg('OUTPUT_GOOD', $ids['produced'], $item, null, $status, '-1'),
        new PostingLeg('OUTPUT_GOOD', $to ?? $f['qc'], $item, null, $status, '1'),
    ];
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
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update items set lot_controlled = false where id = ?', [$f['item']['id']]));
    $prl = (string) $f['prl']['id'];
    $mat = (string) $f['mat']['id'];
    $refused(fn () => $post($mat, [...$consume(), ...$output($f['item']['id'])], 'pr-direct-0001'), 'posted by materials');
    $refused(fn () => $post($prl, [...$consume(), ...$output($f['coil']['id'])], 'pr-direct-0002'), 'output of another item');
    $refused(fn () => $post($prl, [...$consume(), ...$output($f['item']['id'], 'PENDING_QC')], 'pr-direct-0003'), 'good output in the wrong status');
    $refused(fn () => $post($prl, [...$consume(measured: '2'), ...$output($f['item']['id'])], 'pr-direct-0004'), 'consumed differs from what was weighed');
    $refused(fn () => $post($prl, [...$consume(method: 'WEIGHED_REMAINING', measured: '1'), ...$output($f['item']['id'])], 'pr-direct-0005'), 'remaining is not what is left');
    $refused(fn () => $post($prl, $consume(), 'pr-direct-0006'), 'nothing made');
    // a draft or cancelled work order gets no run, straight as SQL either
    $refused(fn () => asApp($this->t, $prl, fn (EoraPostgresConnection $db) => $db->update("update production_runs set work_center_id = work_center_id, note = 'x' where id = ?", [$run['id']])),
        'a run changes what it was opened for');
    expect(prStock($this->t, $f['coil']['id']))->toBe(['MAP-PRS-01 / UNRESTRICTED' => '60', 'RM-01 / UNRESTRICTED' => '20']);
})->group('integration', 'production');

it('cancels a run whose only posting was reversed (it made nothing that stands)', function () {
    $f = prFixture($this, $this->t);
    $run = prOpen($this, $f)->assertCreated()->json('data');
    $posted = prPost($this, $f, $run['id'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'INV');
    $rv = rvRequest($this, $posted)->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    rvApprove($this, $rv)->assertCreated();
    signIn($this, $f['prl'], $this->t);
    $version = spa($this)->getJson("/api/v1/production/runs/{$run['id']}")->assertOk()->assertJsonPath('data.postings', 0)->json('data.version');
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/close", [], ['If-Match' => '"'.$version.'"'])->assertStatus(409);
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/cancel", ['reason' => 'Posted against the wrong run'], ['If-Match' => '"'.$version.'"'])
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
})->group('integration', 'production');

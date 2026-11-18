<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Modules\Planning\Recovery\MaterialCapacity;
use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

it('plans whole sheets once and traces separately counted Plate Grip sides into their respective bending orders', function () {
    $tenant = TenantFixtures::provision(2);
    AccessFixtures::roles($tenant);
    $f = prFixture($this, $tenant);
    foreach (['SUP', 'PPC', 'INV'] as $role) {
        AccessFixtures::grant($tenant, $f['prl']['id'], $role);
    }
    foreach (['CSA', 'INV'] as $role) {
        AccessFixtures::grant($tenant, $f['mat']['id'], $role);
    }
    $use = function (array $user) use ($tenant): void {
        spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();
        signIn($this, $user, $tenant);
    };
    $sheetUnit = AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'SHEET'")->id);
    $items = [];
    foreach (['SHEET' => 'RAW', 'BLANK-RH' => 'SEMI_FINISHED', 'BLANK-LH' => 'SEMI_FINISHED', 'RH' => 'FINISHED', 'LH' => 'FINISHED'] as $code => $type) {
        $items[$code] = spa($this)->postJson('/api/v1/core/items', [
            'code' => $code, 'name' => "Plate Grip {$code}", 'itemType' => $type, 'lotControlled' => true,
            'baseUomId' => $type === 'RAW' ? $sheetUnit : $f['item']['baseUomId'],
        ])->assertCreated()->json('data');
    }
    $pending = [];
    foreach (['BLANK-RH' => 'SHEET', 'RH' => 'BLANK-RH', 'LH' => 'BLANK-LH'] as $output => $input) {
        $isBlank = $input === 'SHEET';
        $bom = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $items[$output]['id'], 'effectiveFrom' => $f['today']])->assertCreated()->json('data');
        spa($this)->putJson("/api/v1/planning/bom-versions/{$bom['id']}/lines", ['lines' => [
            ['lineNo' => 10, 'componentItemId' => $items[$input]['id'], 'grossQtyPer' => $isBlank ? '0.055556' : '1', 'uomId' => $items[$input]['baseUomId']],
        ]], ['If-Match' => '"'.$bom['version'].'"'])->assertOk();
        $route = spa($this)->postJson('/api/v1/planning/routings', [
            'itemId' => $items[$output]['id'], 'plantId' => $f['plant'], 'routingCode' => 'R1', 'routingKind' => 'PRIMARY', 'effectiveFrom' => $f['today'],
        ])->assertCreated()->json('data');
        $rv = spa($this)->getJson('/api/v1/query/planning.routing_versions?filter[routingId]='.$route['id'])->assertOk()->json('data.0');
        $rv = patchWith($this, "/api/v1/planning/routing-versions/{$rv['id']}", [
            'standardYield' => 0.98, 'pairedItemId' => $isBlank ? $items['BLANK-LH']['id'] : null, 'pairsPerSheet' => $isBlank ? 18 : null,
        ], $rv['version'])->assertOk()->json('data');
        spa($this)->putJson("/api/v1/planning/routing-versions/{$rv['id']}/operations", ['operations' => [[
            'opSeq' => 10, 'processTypeId' => $f['blank']['id'], 'workCenterId' => ($isBlank ? $f['wc'] : $f['wc2'])['id'],
            'stdGoodOutputPerHour' => $isBlank ? 550 : 450, 'stdSetupMinutes' => $isBlank ? 15 : 0,
            'cycleBasis' => $isBlank ? 'PER_STROKE' : 'PER_PIECE', 'partsPerCycle' => $isBlank ? 2 : 1,
        ]]], ['If-Match' => '"'.$rv['version'].'"'])->assertOk();
        foreach (["bom-versions/{$bom['id']}", "routing-versions/{$rv['id']}"] as $path) {
            spa($this)->postJson("/api/v1/planning/{$path}/actions/submit")->assertOk();
            $pending[] = $path;
        }
    }
    $use($f['mat']);
    foreach ($pending as $path) {
        spa($this)->postJson("/api/v1/planning/{$path}/actions/approve")->assertOk();
    }
    $use($f['prl']);
    AccessFixtures::run($tenant, function (EoraPostgresConnection $db) use ($items, $f) {
        $capacity = app(MaterialCapacity::class);
        $pool = [$items['SHEET']['id'] => '20'];
        expect($capacity->consume($db, $f['plant'], $items['RH']['id'], '300', $f['today'], $pool, []))->toBeTrue();
        expect($capacity->consume($db, $f['plant'], $items['LH']['id'], '350', $f['today'], $pool, []))->toBeTrue();
        expect(bccomp($pool[$items['SHEET']['id']], '0', 6))->toBe(0)
            ->and(bccomp($pool[$items['BLANK-RH']['id']], '60', 6))->toBe(0)
            ->and(bccomp($pool[$items['BLANK-LH']['id']], '10', 6))->toBe(0);
    });
    foreach (['RH' => '300', 'LH' => '350'] as $side => $quantity) {
        spa($this)->postJson('/api/v1/planning/plan-parameters', ['plantId' => $f['plant'], 'itemId' => $items[$side]['id'], 'safetyStock' => $quantity])->assertCreated();
    }
    $mps = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $mps = mpsAct($this, $mps, 'submit')->assertOk()->json('data');
    $use($f['mat']);
    $mps = mpsAct($this, $mps, 'approve')->assertOk()->json('data');
    $use($f['prl']);
    $mrp = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    $byCode = collect($mrp['items'])->keyBy('itemCode');
    $paired = $byCode['BLANK-RH']['orders'][0];
    expect($byCode['BLANK-RH']['orders'])->toHaveCount(1)
        ->and($byCode['BLANK-LH']['orders'])->toBe([])
        ->and(array_column($byCode['SHEET']['requirements'], 'qty'))->toBe(['20'])
        ->and($paired['pairedWorkload'])->toMatchArray(['pairedItemCode' => 'BLANK-LH', 'qtyPerSide' => '360', 'sheets' => '20', 'runHours' => '1.309', 'setupMinutes' => '15']);
    spa($this)->getJson("/api/v1/planning/mps-versions/{$mps['id']}")->assertOk()->assertJsonPath('data.pairedBlanking.workloads.0.sheets', '20');

    $makeIds = [$paired['id'], $byCode['RH']['orders'][0]['id'], $byCode['LH']['orders'][0]['id']];
    $mrp = spa($this)->postJson("/api/v1/planning/mrp-runs/{$mrp['id']}/actions/release", ['orderIds' => $makeIds])->assertOk()->json('data');
    $orders = [];
    foreach (['BLANK-RH', 'RH', 'LH'] as $code) {
        $id = collect($mrp['items'])->firstWhere('itemCode', $code)['orders'][0]['workOrder']['id'];
        $order = spa($this)->getJson("/api/v1/planning/work-orders/{$id}")->assertOk()->json('data');
        if ($code === 'BLANK-RH') {
            patchWith($this, "/api/v1/planning/work-orders/{$id}", ['qty' => '350'], $order['version'])
                ->assertUnprocessable()->assertJsonValidationErrors(['qty']);
        }
        $orders[$code] = woAct($this, $id, 'submit', $order['version'])->assertOk()->json('data');
    }
    $next = spa($this)->postJson('/api/v1/planning/mrp-runs', ['mpsVersionId' => $mps['id']])->assertCreated()->json('data');
    expect(collect($next['items'])->firstWhere('itemCode', 'BLANK-RH')['orders'])->toBe([])
        ->and(collect($next['items'])->firstWhere('itemCode', 'BLANK-LH')['orders'])->toBe([]);
    $use($f['mat']);
    foreach ($orders as $code => $order) {
        $orders[$code] = woAct($this, $order['id'], 'release', $order['version'])->assertOk()->json('data');
    }
    expect($orders['BLANK-RH']['materials'][0]['requiredQty'])->toBe('20');
    $rawLot = spa($this)->postJson('/api/v1/inventory/receipts', [
        'plantId' => $f['plant'], 'itemId' => $items['SHEET']['id'], 'locationId' => $f['rack'], 'supplierId' => $f['supplier'],
        'supplierLotNo' => 'PLATE-HEAT', 'qty' => '20',
    ], ['Idempotency-Key' => 'paired-receive-sheets'])->assertCreated()->json('data.lotId');
    $issue = fn (string $order, array $wc, string $item, string $lot, string $rack, string $qty, string $key) => mfIssue($this, $f, [
        'workOrderId' => $orders[$order]['id'], 'workCenterId' => $wc['id'],
        'lines' => [['itemId' => $items[$item]['id'], 'lotId' => $lot, 'fromLocationId' => $rack, 'qty' => $qty]],
    ], $key);
    $issue('BLANK-RH', $f['wc'], 'SHEET', $rawLot, $f['rack'], '20', 'paired-issue-sheet')->assertCreated();
    $use($f['prl']);
    $run = prOpen($this, $f, ['workOrderId' => $orders['BLANK-RH']['id']])->assertCreated()->assertJsonPath('data.pairedOutput.itemCode', 'BLANK-LH')->json('data');
    $post = [
        'runId' => $run['id'], 'goodQty' => '350', 'pairedGoodQty' => '340', 'pairedNgRejectQty' => '10', 'semiFinishedLocationId' => $f['rack2'],
        'consumptions' => [['itemId' => $items['SHEET']['id'], 'lotId' => $rawLot, 'method' => 'COUNTED_REMAINING', 'qty' => '0', 'evidence' => 'Sheets counted']],
    ];
    $result = spa($this)->postJson('/api/v1/production/postings', $post, ['Idempotency-Key' => 'paired-blanking'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/production/postings', $post, ['Idempotency-Key' => 'paired-blanking'])->assertOk()->assertJsonPath('data.outputLots', $result['outputLots']);
    $lots = array_column($result['outputLots'], 'id', 'itemCode');
    expect($lots)->toHaveCount(2)->and(prStock($tenant, $items['BLANK-RH']['id']))->toMatchArray(['RM-02 / UNRESTRICTED' => '350'])
        ->and(prStock($tenant, $items['BLANK-LH']['id']))->toMatchArray(['RM-02 / UNRESTRICTED' => '340', 'NG-01 / REJECTED' => '10']);
    $consumed = AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select trim_scale(sum(-qty_signed))::text as qty from inventory_movements where document_id = ? and movement_code = 'CONSUME' and qty_signed < 0",
        [$result['documentId']],
    )->qty);
    expect($consumed)->toBe('20');

    $use($f['mat']);
    $issue('LH', $f['wc2'], 'BLANK-RH', $lots['BLANK-RH'], $f['rack2'], '350', 'paired-wrong-side')->assertUnprocessable();
    $issue('LH', $f['wc2'], 'BLANK-LH', $lots['BLANK-LH'], $f['rack2'], '350', 'paired-lh-too-many')->assertConflict();
    $issue('LH', $f['wc2'], 'BLANK-LH', $lots['BLANK-LH'], $f['rack2'], '340', 'paired-bend-lh')->assertCreated();
    $use($f['prl']);
    $bend = prOpen($this, $f, ['workOrderId' => $orders['LH']['id'], 'workCenterId' => $f['wc2']['id']])->assertCreated()->json('data');
    $formed = spa($this)->postJson('/api/v1/production/postings', [
        'runId' => $bend['id'], 'goodQty' => '340',
        'consumptions' => [['itemId' => $items['BLANK-LH']['id'], 'lotId' => $lots['BLANK-LH'], 'method' => 'COUNTED_REMAINING', 'qty' => '0', 'evidence' => 'Blank count']],
    ], ['Idempotency-Key' => 'paired-post-bend'])->assertCreated()->json('data');
    $trace = spa($this)->getJson('/api/v1/traceability/lot-trace?'.http_build_query([
        'q' => $formed['lotNo'], 'lotId' => $formed['lotId'], 'direction' => 'backward',
    ]))->assertOk()->json('data.nodes');
    expect(array_column($trace, 'itemCode'))->toBe(['LH', 'BLANK-LH', 'SHEET'])
        ->and(app(BalanceVerification::class)->verify($tenant['tenant']))->toBe([]);
    $sheetNo = AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select lot_no from lots where id = ?', [$rawLot])->lot_no);
    $forward = spa($this)->getJson('/api/v1/traceability/lot-trace?'.http_build_query([
        'q' => $sheetNo, 'lotId' => $rawLot, 'direction' => 'forward',
    ]))->assertOk()->json('data.nodes');
    expect(array_column($forward, 'itemCode'))->toContain('BLANK-RH', 'BLANK-LH', 'LH');
})->group('integration', 'production');

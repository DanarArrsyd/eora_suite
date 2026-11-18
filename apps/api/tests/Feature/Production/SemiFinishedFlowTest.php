<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

it('records 650 blanks, lets Forming draw 350 from that lot, and traces the remainder and the finished output', function (bool $inspected) {
    $tenant = TenantFixtures::provision(2);
    AccessFixtures::roles($tenant);
    $f = prFixture($this, $tenant);
    AccessFixtures::grant($tenant, $f['prl']['id'], 'SUP');
    AccessFixtures::grant($tenant, $f['prl']['id'], 'PPC');
    AccessFixtures::grant($tenant, $f['mat']['id'], 'CSA');
    AccessFixtures::grant($tenant, $f['mat']['id'], 'QCI');
    AccessFixtures::grant($tenant, $f['mat']['id'], 'INV');
    $use = function (array $user) use ($tenant): void {
        spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();
        signIn($this, $user, $tenant);
    };
    $blank = spa($this)->postJson('/api/v1/core/items', [
        'code' => 'BEAM-BLK', 'name' => 'Shared beam blank', 'itemType' => 'SEMI_FINISHED', 'baseUomId' => $f['item']['baseUomId'],
        'lotControlled' => true, 'inspectionRequired' => $inspected,
    ])->assertCreated()->json('data');
    $fg = [];
    foreach (['RH', 'LH'] as $side) {
        $fg[$side] = spa($this)->postJson('/api/v1/core/items', [
            'code' => "BEAM-{$side}", 'name' => "Beam {$side}", 'itemType' => 'FINISHED', 'baseUomId' => $f['item']['baseUomId'], 'lotControlled' => true,
        ])->assertCreated()->json('data');
    }
    $pending = [];
    foreach ([$blank, ...array_values($fg)] as $item) {
        $isBlank = $item['id'] === $blank['id'];
        $component = $isBlank ? $f['coil'] : $blank;
        $bom = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $item['id'], 'effectiveFrom' => $f['today']])->assertCreated()->json('data');
        spa($this)->putJson("/api/v1/planning/bom-versions/{$bom['id']}/lines", ['lines' => [
            ['lineNo' => 10, 'componentItemId' => $component['id'], 'grossQtyPer' => $isBlank ? '0.03' : '1', 'uomId' => $component['baseUomId']],
        ]], ['If-Match' => '"'.$bom['version'].'"'])->assertOk();
        $routing = spa($this)->postJson('/api/v1/planning/routings', [
            'itemId' => $item['id'], 'plantId' => $f['plant'], 'routingCode' => 'R1', 'routingKind' => 'PRIMARY', 'effectiveFrom' => $f['today'],
        ])->assertCreated()->json('data');
        $rv = spa($this)->getJson('/api/v1/query/planning.routing_versions?filter[routingId]='.$routing['id'])->assertOk()->json('data.0');
        $rv = patchWith($this, "/api/v1/planning/routing-versions/{$rv['id']}", ['standardYield' => 0.98], $rv['version'])->assertOk()->json('data');
        spa($this)->putJson("/api/v1/planning/routing-versions/{$rv['id']}/operations", ['operations' => [[
            'opSeq' => 10, 'processTypeId' => $f['blank']['id'], 'workCenterId' => ($isBlank ? $f['wc'] : $f['wc2'])['id'],
            'stdCycleSeconds' => 1, 'cycleBasis' => 'PER_PIECE',
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
    $orders = [];
    foreach (['blank' => [$blank, '650'], 'rh' => [$fg['RH'], '350'], 'lh' => [$fg['LH'], '301']] as $key => [$item, $qty]) {
        $draft = woCreate($this, $f, ['itemId' => $item['id'], 'qty' => $qty])->assertCreated()->json('data');
        $orders[$key] = woAct($this, $draft['id'], 'submit', $draft['version'])->assertOk()->json('data');
    }
    $use($f['mat']);
    foreach ($orders as $key => $order) {
        $orders[$key] = woAct($this, $order['id'], 'release', $order['version'])->assertOk()->json('data');
    }
    $issue = fn (string $order, string $center, array $line, string $key) => mfIssue($this, $f, [
        'workOrderId' => $orders[$order]['id'], 'workCenterId' => $center, 'lines' => [$line],
    ], $key);
    $issue('blank', $f['wc']['id'], ['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '20'], 'step6-coil')->assertCreated();

    $use($f['prl']);
    $run = prOpen($this, $f, ['workOrderId' => $orders['blank']['id']])->assertCreated()->assertJsonPath('data.itemType', 'SEMI_FINISHED')->json('data');
    $body = [
        'runId' => $run['id'], 'goodQty' => '650',
        'consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '20', 'evidence' => 'Scale']],
    ];
    if (! $inspected) {
        spa($this)->postJson('/api/v1/production/postings', $body, ['Idempotency-Key' => 'step6-no-rack'])->assertUnprocessable()
            ->assertJsonValidationErrors(['semiFinishedLocationId']);
        $body['semiFinishedLocationId'] = $f['rack2'];
    }
    $post = spa($this)->postJson('/api/v1/production/postings', $body, ['Idempotency-Key' => 'step6-blank'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/production/postings', $body, ['Idempotency-Key' => 'step6-blank'])->assertOk()->assertJsonPath('data.lotId', $post['lotId']);

    $use($f['mat']);
    $line = ['itemId' => $blank['id'], 'lotId' => $post['lotId'], 'fromLocationId' => $f['rack2'], 'qty' => '350'];
    if ($inspected) {
        expect(prStock($tenant, $blank['id']))->toMatchArray(['QC-01 / PENDING_QC' => '650']);
        $issue('rh', $f['wc2']['id'], $line, 'step6-before-qc')->assertConflict();
        $pendingQc = AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select id from stock_balances where lot_id = ? and stock_status = 'PENDING_QC'", [$post['lotId']],
        )->id);
        spa($this)->postJson('/api/v1/quality/dispositions', ['stockLineId' => $pendingQc, 'semiFinishedLocationId' => $f['rack2'],
            'lines' => [['outcome' => 'RELEASE', 'qty' => '650']]], ['Idempotency-Key' => 'step6-qc-release'])->assertCreated();
    }
    $issue('rh', $f['wc2']['id'], $line, 'step6-forming-issue')->assertCreated();
    $issue('lh', $f['wc2']['id'], array_replace($line, ['qty' => '301']), 'step6-not-available')->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    expect(prStock($tenant, $blank['id']))->toMatchArray(['RM-02 / UNRESTRICTED' => '300', 'MAP-WLD-01 / UNRESTRICTED' => '350']);

    $use($f['prl']);
    $run = prOpen($this, $f, ['workOrderId' => $orders['rh']['id'], 'workCenterId' => $f['wc2']['id']])->assertCreated()->json('data');
    $finished = spa($this)->postJson('/api/v1/production/postings', [
        'runId' => $run['id'], 'goodQty' => '350',
        'consumptions' => [['itemId' => $blank['id'], 'lotId' => $post['lotId'], 'method' => 'COUNTED_REMAINING', 'qty' => '0', 'evidence' => 'Counted']],
    ], ['Idempotency-Key' => 'step6-forming'])->assertCreated()->json('data');
    expect(prStock($tenant, $blank['id']))->toMatchArray(['RM-02 / UNRESTRICTED' => '300'])
        ->not->toHaveKey('MAP-WLD-01 / UNRESTRICTED');

    $use($f['mat']);
    $trace = spa($this)->getJson('/api/v1/traceability/lot-trace?'.http_build_query([
        'q' => $finished['lotNo'], 'lotId' => $finished['lotId'], 'direction' => 'backward',
    ]))->assertOk()->json('data.nodes');
    expect(array_column($trace, 'itemCode'))->toBe([$fg['RH']['code'], $blank['code'], $f['coil']['code']]);
    $coilNo = AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select lot_no from lots where id = ?', [$f['lot']])->lot_no);
    $forward = spa($this)->getJson('/api/v1/traceability/lot-trace?'.http_build_query([
        'q' => $coilNo, 'lotId' => $f['lot'], 'direction' => 'forward',
    ]))->assertOk()->json('data.nodes');
    expect(collect($forward)->firstWhere('itemCode', $fg['RH']['code'])['depth'])->toBe(2)
        ->and(app(BalanceVerification::class)->verify($tenant['tenant']))->toBe([]);
})->with(['direct to stock' => false, 'inspection required' => true])->group('integration', 'production');

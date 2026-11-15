<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 exit (doc 33 §4 item 5, ADR-0014 §4): every posting endpoint of M4 keeps one key for one command — a retry answers
 * as it posted, the key used for another request (another endpoint or another payload) is refused with 409 before
 * anything is read — and waits for a posting that holds its stock instead of both committing.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * shFixture (line 1 and 2 staged); the FG warehouse user also holds the other M4 posting roles, so one session reaches
 * every endpoint.
 *
 * @return array<string, mixed>
 */
function pcFixture(object $test, array $t): array
{
    $f = shFixture($test, $t);
    foreach (['MAT', 'PRL', 'QCI', 'DLV', 'PPC'] as $role) {
        AccessFixtures::grant($t, $f['fgw']['id'], $role);
    }
    $run = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from production_runs where work_order_id = ? order by opened_at limit 1', [$f['wo']['id']])->id);
    // a third order of 20 with nothing allocated yet
    $so = spa($test)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $filled = spa($test)->json('PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $f['part'], 'customerPoNo' => 'PO-3', 'customerPoLine' => '10',
        'schedules' => [['requirementDate' => date('Y-m-d', strtotime($f['today'].' +5 days')), 'qty' => '20']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    $third = spa($test)->json('POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$filled['version'].'"'])->assertOk()->json('data');

    return ['runId' => $run, 'line3' => $third['lines'][0]['schedules'][0]['id']] + $f;
}

it('refuses a key used for another request on every M4 posting endpoint; a retry replays (ADR-0014 §4)', function () {
    $f = pcFixture($this, $this->t);
    [, $b] = $f['lots'];
    $used = 'sh-pick-0001'; // the pick of the fixture
    $key = fn ($response) => $response->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    $post = fn (string $uri, array $body, string $k) => spa($this)->postJson($uri, $body, ['Idempotency-Key' => $k]);

    // another endpoint's key, with a request that would otherwise be valid
    $key($post('/api/v1/delivery/shipments', ['plantId' => $f['plant'], 'customerId' => $f['customer'],
        'lines' => [['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgA'], 'qty' => '1']]], $used));
    $key($post('/api/v1/delivery/allocations', ['scheduleLineId' => $f['line2'], 'lines' => [['stockLineId' => ($f['line'])($b, 'UNRESTRICTED'), 'qty' => '1']]], $used));
    $key($post('/api/v1/warehouse/fg-receipts', ['plantId' => $f['plant'], 'lines' => [['stockLineId' => ($f['line'])($b, 'UNRESTRICTED'), 'qty' => '1']]], $used));
    $key($post('/api/v1/quality/dispositions', ['stockLineId' => ($f['line'])($b, 'UNRESTRICTED'), 'lines' => [['outcome' => 'RELEASE', 'qty' => '1']]], $used));
    $key($post('/api/v1/production/postings', ['runId' => $f['runId'],
        'consumptions' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_CONSUMED', 'qty' => '1', 'evidence' => 'Scale S-01']], 'goodQty' => '1'], $used));
    $key($post('/api/v1/inventory/material-issues', ['workOrderId' => $f['wo']['id'], 'workCenterId' => $f['wc']['id'],
        'lines' => [['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'fromLocationId' => $f['rack'], 'qty' => '1']]], $used));
    $key($post('/api/v1/inventory/material-returns', ['workOrderId' => $f['wo']['id'], 'workCenterId' => $f['wc']['id'],
        'itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'qty' => '1'], $used));
    $key($post("/api/v1/planning/work-orders/{$f['wo']['id']}/close-exceptions", ['reason' => 'Coil end too short'], $used));

    // the same endpoint and key with another payload; the same payload replays
    $ship = fn (string $qty) => $post('/api/v1/delivery/shipments', ['plantId' => $f['plant'], 'customerId' => $f['customer'],
        'lines' => [['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgA'], 'qty' => $qty]]], 'pc-ship-0001');
    $posted = $ship('10')->assertCreated()->json('data.documentId');
    $key($ship('5'));
    $ship('10')->assertOk()->assertJsonPath('data.documentId', $posted);
    $allocate = fn (string $qty) => $post('/api/v1/delivery/allocations', ['scheduleLineId' => $f['line3'], 'lines' => [['stockLineId' => ($f['line'])($b, 'UNRESTRICTED'), 'qty' => $qty]]], 'pc-alloc-0001');
    $allocation = $allocate('5')->assertCreated()->json('data.allocationIds.0');
    $key($allocate('4'));
    $pick = fn (string $qty) => $post('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => [['allocationId' => $allocation, 'qty' => $qty]]], 'pc-pick-0001');
    $picked = $pick('5')->assertCreated()->json('data.documentId');
    $key($pick('4'));
    $pick('5')->assertOk()->assertJsonPath('data.documentId', $picked);
})->group('integration', 'delivery');

it('makes a shipment and a pick wait for a posting that holds their stock (ADR-0014 §4)', function () {
    $f = pcFixture($this, $this->t);
    [, $b] = $f['lots'];
    $allocation = spa($this)->postJson('/api/v1/delivery/allocations', ['scheduleLineId' => $f['line3'], 'lines' => [['stockLineId' => ($f['line'])($b, 'UNRESTRICTED'), 'qty' => '5']]],
        ['Idempotency-Key' => 'pc-alloc-0002'])->assertCreated()->json('data.allocationIds.0');
    $tx = app(TenantTransaction::class);
    config(['database.connections.pgsql_takeover' => config('database.connections.pgsql')]);
    // another posting holds the staged lot and the FG rack line while these requests come in: both wait, then give up
    $tx->run(TenantContext::forTenant($this->t['tenant'], (string) $f['dlv']['id']), function (EoraPostgresConnection $db) use ($f, $b, $allocation) {
        $db->select('select 1 from stock_balances where id in (?, ?) for update', [$f['stgA'], ($f['line'])($b, 'UNRESTRICTED')]);
        shShip($this, $f, [['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgA'], 'qty' => '10']], [], 'pc-ship-0002')->assertStatus(409)->assertJsonPath('code', 'STOCK_BUSY');
        spa($this)->postJson('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => [['allocationId' => $allocation, 'qty' => '5']]], ['Idempotency-Key' => 'pc-pick-0002'])
            ->assertStatus(409)->assertJsonPath('code', 'STOCK_BUSY');
    }, 'pgsql_takeover');
    // released: the same requests post
    shShip($this, $f, [['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgA'], 'qty' => '10']], [], 'pc-ship-0002')->assertCreated();
    spa($this)->postJson('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => [['allocationId' => $allocation, 'qty' => '5']]], ['Idempotency-Key' => 'pc-pick-0002'])->assertCreated();
})->group('integration', 'delivery');

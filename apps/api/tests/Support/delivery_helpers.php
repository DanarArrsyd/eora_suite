<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Delivery tests (M4): received FG, a confirmed order of the item with one firm schedule line, staging. */

/**
 * fgFixture with both lots received (FG-01: 90 of lot A, FG-02: 90 of lot B), a confirmed order of the item for 100 on
 * one firm schedule line and a staging location. Ends signed in as delivery (DLV).
 *
 * @return array<string, mixed>
 */
function dlFixture(object $test, array $t): array
{
    $f = fgFixture($test, $t);
    [$a, $b] = $f['lots'];
    fgReceive($test, $f, [['stockLineId' => ($f['line'])($a), 'qty' => '90']], ['locationId' => $f['fg1']], 'dl-fgr-0001')->assertCreated();
    fgReceive($test, $f, [['stockLineId' => ($f['line'])($b), 'qty' => '90']], ['locationId' => $f['fg2']], 'dl-fgr-0002')->assertCreated();
    ['part' => $part, 'staging' => $staging, 'customer' => $customer] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $customer = (string) $db->selectOne("insert into customers (tenant_id, code, name) values (?, 'OEM-A', 'Customer OEM-A') returning id", [$t['tenant']])->id;
        $wh = (string) $db->selectOne("select id from warehouses where plant_id = ? and code = 'RM'", [$f['plant']])->id;

        return [
            'part' => (string) $db->selectOne("insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) values (?, ?, ?, 'A-100', '2026-01-01') returning id",
                [$t['tenant'], $customer, $f['item']['id']])->id,
            'staging' => (string) $db->selectOne("insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, 'STG-01', 'Staging', 'STAGING') returning id",
                [$t['tenant'], $f['plant'], $wh])->id,
            'customer' => $customer,
        ];
    });
    $ppc = rtSwitch($test, $t, 'PPC');
    $so = spa($test)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $customer])->assertCreated()->json('data');
    $filled = spa($test)->json('PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $part, 'customerPoNo' => 'PO-1', 'customerPoLine' => '10',
        'schedules' => [['requirementDate' => date('Y-m-d', strtotime($f['today'].' +3 days')), 'qty' => '100']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    $confirmed = spa($test)->json('POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$filled['version'].'"'])->assertOk()->json('data');
    $dlv = rtSwitch($test, $t, 'DLV');

    // the delivery keys win over earlier fixtures' keys of the same name (an earlier fixture has its own customer)
    return ['so' => $confirmed, 'schedule' => $confirmed['lines'][0]['schedules'][0]['id'], 'staging' => $staging, 'customer' => $customer, 'dlv' => $dlv, 'ppc' => $ppc, 'part' => $part] + $f;
}

function dlAllocate(object $test, array $f, array $lines, string $key = 'dl-alloc-0001')
{
    return spa($test)->postJson('/api/v1/delivery/allocations', ['scheduleLineId' => $f['schedule'], 'lines' => $lines], ['Idempotency-Key' => $key]);
}

/**
 * dlFixture plus a second order of the same customer (30 PCS on one schedule line); line 1 gets 100 allocated and picked
 * (90 of lot A, 10 of lot B), line 2 gets 30 of lot B allocated and picked. Ends signed in as the FG warehouse.
 *
 * @return array<string, mixed>
 */
function shFixture(object $test, array $t): array
{
    $f = dlFixture($test, $t);
    [$a, $b] = $f['lots'];
    signIn($test, $f['ppc'], $t);
    $so = spa($test)->postJson('/api/v1/demand/sales-orders', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $filled = spa($test)->json('PUT', "/api/v1/demand/sales-orders/{$so['id']}/lines", ['lines' => [[
        'customerItemId' => $f['part'], 'customerPoNo' => 'PO-2', 'customerPoLine' => '10',
        'schedules' => [['requirementDate' => date('Y-m-d', strtotime($f['today'].' +4 days')), 'qty' => '30']],
    ]]], ['If-Match' => '"'.$so['version'].'"'])->assertOk()->json('data');
    $second = spa($test)->json('POST', "/api/v1/demand/sales-orders/{$so['id']}/actions/confirm", [], ['If-Match' => '"'.$filled['version'].'"'])->assertOk()->json('data');
    $line2 = $second['lines'][0]['schedules'][0]['id'];

    signIn($test, $f['dlv'], $t);
    $fgA = ($f['line'])($a, 'UNRESTRICTED');
    $fgB = ($f['line'])($b, 'UNRESTRICTED');
    $alloc1 = dlAllocate($test, $f, [['stockLineId' => $fgA, 'qty' => '90'], ['stockLineId' => $fgB, 'qty' => '10']], 'sh-alloc-0001')->assertCreated()->json('data.allocationIds');
    $alloc2 = spa($test)->postJson('/api/v1/delivery/allocations', ['scheduleLineId' => $line2, 'lines' => [['stockLineId' => $fgB, 'qty' => '30']]], ['Idempotency-Key' => 'sh-alloc-0002'])
        ->assertCreated()->json('data.allocationIds');
    signIn($test, $f['fgw'], $t);
    spa($test)->postJson('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => [
        ['allocationId' => $alloc1[0], 'qty' => '90'], ['allocationId' => $alloc1[1], 'qty' => '10'], ['allocationId' => $alloc2[0], 'qty' => '30'],
    ]], ['Idempotency-Key' => 'sh-pick-0001'])->assertCreated();
    $staged = fn (string $line, string $lot) => (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select b.id from stock_balances b join locations l on l.id = b.location_id where l.location_type = 'STAGING' and b.lot_id = ? and b.plant_id = ?", [$lot, $f['plant']])->id);

    return ['so2' => $second, 'line2' => $line2, 'stgA' => $staged($f['schedule'], $a), 'stgB' => $staged($line2, $b)] + $f;
}

function shShip(object $test, array $f, array $lines, array $body = [], string $key = 'sh-ship-0001')
{
    return spa($test)->postJson('/api/v1/delivery/shipments', $body + ['plantId' => $f['plant'], 'customerId' => $f['customer'], 'lines' => $lines], ['Idempotency-Key' => $key]);
}

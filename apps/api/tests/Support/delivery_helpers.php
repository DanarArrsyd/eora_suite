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

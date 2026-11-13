<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Material flow tests (M4): a released WO with received material, issues and returns through the API. */

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

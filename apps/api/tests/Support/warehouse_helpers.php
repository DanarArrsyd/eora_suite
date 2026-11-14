<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Warehouse and delivery tests (M4): QC-released output, FG locations and FG receipts through the API. */

/**
 * prFixture (item without inspection) with two runs posted: two output lots QC_RELEASED at QC-01 (90 each), and two FG
 * locations. Ends signed in as the FG warehouse (FGW).
 *
 * @return array<string, mixed>
 */
function fgFixture(object $test, array $t): array
{
    $f = prFixture($test, $t);
    $lots = [];
    foreach (['fg-run-0001', 'fg-run-0002'] as $n => $key) {
        $run = prOpen($test, $f)->assertCreated()->json('data');
        $lots[] = prPost($test, $f, $run['id'], $n === 0 ? [] : ['consumptions' => [
            ['itemId' => $f['coil']['id'], 'lotId' => $f['lot'], 'method' => 'WEIGHED_REMAINING', 'qty' => '6', 'evidence' => 'Scale S-01'],
        ]], $key)->assertCreated()->json('data.lotId');
    }
    [$fg1, $fg2] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $wh = (string) $db->selectOne("select id from warehouses where plant_id = ? and code = 'RM'", [$f['plant']])->id;
        $loc = fn (string $code) => (string) $db->selectOne(
            "insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, ?, ?, 'FG_WAREHOUSE') returning id",
            [$t['tenant'], $f['plant'], $wh, $code, "FG {$code}"],
        )->id;

        return [$loc('FG-01'), $loc('FG-02')];
    });
    $line = fn (string $lot, string $status = 'QC_RELEASED') => (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select id from stock_balances where lot_id = ? and stock_status = ?', [$lot, $status])->id);
    $fgw = rtSwitch($test, $t, 'FGW');

    return $f + ['lots' => $lots, 'fg1' => $fg1, 'fg2' => $fg2, 'line' => $line, 'fgw' => $fgw];
}

/** @return array<string, string> "location code / status" => quantity of the item, summed over its lots */
function fgStock(array $t, string $item): array
{
    return AccessFixtures::run($t, fn (EoraPostgresConnection $db) => array_column($db->select(
        "select l.code || ' / ' || b.stock_status as k, trim_scale(sum(b.qty))::text as q from stock_balances b join locations l on l.id = b.location_id
          where b.item_id = ? and b.qty <> 0 group by 1 order by 1",
        [$item],
    ), 'q', 'k'));
}

function fgReceive(object $test, array $f, array $lines, array $body = [], string $key = 'fg-receipt-0001')
{
    return spa($test)->postJson('/api/v1/warehouse/fg-receipts', $body + ['plantId' => $f['plant'], 'lines' => $lines], ['Idempotency-Key' => $key]);
}

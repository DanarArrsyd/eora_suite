<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Production tests (M4): a released WO with issued material, QC/NG/scrap areas, runs and postings through the API. */

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

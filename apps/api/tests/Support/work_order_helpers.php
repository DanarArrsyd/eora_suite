<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Work order tests (M4): a released-ready master (BOM + primary routing approved) and the WO actions through the API. */

/**
 * As a CSA: the routing master of rtSetup, a business calendar, a BOM (coil 0.5 KG + nut 2 per piece) and the primary
 * routing, both submitted and approved by a second CSA; a customer with a part mapping of the item. Ends signed in as PPC.
 *
 * @return array<string, mixed>
 */
function woFixture(object $test, array $t): array
{
    orgUser($test, $t, 'CSA');
    $s = rtSetup($test, $t);
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '00:00', '2026-01-01')", [$t['tenant']],
    ));
    $unit = fn (string $code) => (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from uoms where code = ?', [$code])->id);
    $coil = spa($test)->postJson('/api/v1/core/items', ['code' => 'COIL-9', 'name' => 'Coil', 'itemType' => 'RAW', 'baseUomId' => $unit('KG')])->assertCreated()->json('data');
    $nut = spa($test)->postJson('/api/v1/core/items', ['code' => 'NUT-9', 'name' => 'Nut', 'itemType' => 'RAW', 'baseUomId' => $unit('PCS')])->assertCreated()->json('data');
    $bom = spa($test)->postJson('/api/v1/planning/bom-versions', ['itemId' => $s['item']['id'], 'effectiveFrom' => rtDay($t)])->assertCreated()->json('data');
    $bom = spa($test)->putJson('/api/v1/planning/bom-versions/'.$bom['id'].'/lines', ['lines' => [
        ['lineNo' => 10, 'componentItemId' => $coil['id'], 'grossQtyPer' => '0.5', 'uomId' => $coil['baseUomId']],
        ['lineNo' => 20, 'componentItemId' => $nut['id'], 'grossQtyPer' => '2', 'uomId' => $nut['baseUomId']],
    ]], ['If-Match' => '"'.$bom['version'].'"'])->assertOk()->json('data');
    spa($test)->postJson('/api/v1/planning/bom-versions/'.$bom['id'].'/actions/submit')->assertOk();
    [, $rv] = rtDraft($test, $t, $s);
    spa($test)->postJson('/api/v1/planning/routing-versions/'.$rv['id'].'/actions/submit')->assertOk();
    $customer = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("insert into customers (tenant_id, code, name) values (?, 'OEM-W', 'Customer W') returning id", [$t['tenant']])->id);
    $part = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) values (?, ?, ?, 'W-100', '2026-01-01') returning id",
        [$t['tenant'], $customer, $s['item']['id']],
    )->id);
    rtSwitch($test, $t, 'CSA');
    spa($test)->postJson('/api/v1/planning/bom-versions/'.$bom['id'].'/actions/approve')->assertOk();
    spa($test)->postJson('/api/v1/planning/routing-versions/'.$rv['id'].'/actions/approve')->assertOk();
    rtSwitch($test, $t, 'PPC');

    return $s + ['coil' => $coil, 'nut' => $nut, 'bom' => $bom, 'rv' => $rv, 'customer' => $customer, 'part' => $part, 'today' => rtDay($t)];
}

function woCreate(object $test, array $s, array $body = [])
{
    return spa($test)->postJson('/api/v1/planning/work-orders', $body + [
        'plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'qty' => '100', 'dueDate' => rtDayFrom($s['today'], 5), 'reasonCode' => 'NO_MPS_YET',
    ]);
}

function rtDayFrom(string $day, int $days): string
{
    return date('Y-m-d', strtotime("{$day} {$days} days"));
}

function woAct(object $test, string $id, string $action, int $version, array $body = [])
{
    return spa($test)->postJson("/api/v1/planning/work-orders/{$id}/actions/{$action}", $body, ['If-Match' => '"'.$version.'"']);
}

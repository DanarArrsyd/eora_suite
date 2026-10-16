<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Helpers shared by the routing and BOM feature tests (doc 29 §1.3–§1.4). */

/** Today (YYYY-MM-DD) in the tenant's time zone, optionally shifted by $days. */
function rtDay(array $t, int $days = 0): string
{
    return (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select ((now() at time zone timezone)::date + ?::int)::text as d from tenants where id = ?', [$days, $t['tenant']],
    )->d);
}

/**
 * Master data a routing needs, created as a tenant-wide CSA: an item, a press line with two machines, another line, a die
 * linked to the item, and two process types.
 *
 * @return array<string, mixed>
 */
function rtSetup(object $test, array $t): array
{
    $plant = $t['plants'][0];
    $pcs = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'PCS'")->id);
    $item = spa($test)->postJson('/api/v1/core/items', ['code' => 'BRK-100', 'name' => 'Bracket', 'itemType' => 'FINISHED', 'baseUomId' => $pcs])->assertCreated()->json('data');
    $wc = spa($test)->postJson('/api/v1/core/work-centers', ['plantId' => $plant, 'code' => 'PRS-01', 'name' => 'Press line', 'workCenterType' => 'PRESS'])->assertCreated()->json('data');
    $wc2 = spa($test)->postJson('/api/v1/core/work-centers', ['plantId' => $plant, 'code' => 'WLD-01', 'name' => 'Weld line', 'workCenterType' => 'WELD'])->assertCreated()->json('data');
    $m1 = spa($test)->postJson('/api/v1/core/machines', ['workCenterId' => $wc['id'], 'code' => 'M-200', 'name' => 'Press 200 t'])->assertCreated()->json('data');
    $m2 = spa($test)->postJson('/api/v1/core/machines', ['workCenterId' => $wc2['id'], 'code' => 'W-1', 'name' => 'Welder'])->assertCreated()->json('data');
    $die = spa($test)->postJson('/api/v1/core/dies', ['plantId' => $plant, 'code' => 'D-100', 'name' => 'Bracket die', 'dieType' => 'DIE', 'cavities' => 2])->assertCreated()->json('data');
    $loose = spa($test)->postJson('/api/v1/core/dies', ['plantId' => $plant, 'code' => 'D-200', 'name' => 'Other die', 'dieType' => 'DIE'])->assertCreated()->json('data');
    spa($test)->postJson('/api/v1/core/die-items', ['dieId' => $die['id'], 'itemId' => $item['id'], 'partsPerStroke' => 2])->assertCreated();
    $blank = spa($test)->postJson('/api/v1/planning/process-types', ['code' => 'BLANK', 'name' => 'Blanking', 'category' => 'TRANSFORMATION', 'defaultWorkCenterType' => 'PRESS'])
        ->assertCreated()->json('data');
    $check = spa($test)->postJson('/api/v1/planning/process-types', ['code' => 'INSP', 'name' => 'Final inspection', 'category' => 'INSPECTION'])->assertCreated()->json('data');

    return compact('plant', 'item', 'wc', 'wc2', 'm1', 'm2', 'die', 'loose', 'blank', 'check');
}

/** @return list<array<string, mixed>> a valid two-operation list */
function rtOperations(array $s): array
{
    return [
        ['opSeq' => 10, 'processTypeId' => $s['blank']['id'], 'workCenterId' => $s['wc']['id'], 'stdSetupMinutes' => 30, 'stdCycleSeconds' => 4.5,
            'cycleBasis' => 'PER_STROKE', 'partsPerCycle' => 2, 'requiresTooling' => true, 'isCountPoint' => false,
            'machines' => [['machineId' => $s['m1']['id'], 'isPreferred' => true]], 'tools' => [['dieId' => $s['die']['id'], 'cavitiesUsed' => 2]]],
        ['opSeq' => 20, 'processTypeId' => $s['check']['id'], 'workCenterId' => $s['wc2']['id'], 'stdCycleSeconds' => 12, 'cycleBasis' => 'PER_PIECE',
            'inspectionRequired' => true, 'inspections' => [['characteristic' => 'Hole position', 'samplingNote' => '1 of 50']]],
    ];
}

/** A routing with a DRAFT v1 carrying operations and a standard yield; returns [routing, version]. */
function rtDraft(object $test, array $t, array $s, int $startIn = 0): array
{
    $routing = spa($test)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R1',
        'routingKind' => 'PRIMARY', 'effectiveFrom' => rtDay($t, $startIn)])->assertCreated()->json('data');
    $v = spa($test)->getJson('/api/v1/query/planning.routing_versions?filter[routingId]='.$routing['id'])->assertOk()->json('data.0');
    $v = patchWith($test, '/api/v1/planning/routing-versions/'.$v['id'], ['standardYield' => 0.98], $v['version'])->assertOk()->json('data');
    $v = spa($test)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => rtOperations($s)], ['If-Match' => '"'.$v['version'].'"'])
        ->assertOk()->json('data');

    return [$routing, $v];
}

function rtSwitch(object $test, array $t, string $role, array $scope = ['TENANT']): array
{
    spa($test)->postJson('/api/v1/auth/logout')->assertNoContent();

    return orgUser($test, $t, $role, $scope);
}

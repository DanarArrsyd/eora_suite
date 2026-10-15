<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 3a (doc 29 §1.3, doc 26 §2.3–§2.4a, D-058, D-060): process types, routings and their versions through the
 * HTTP API — draft, operation list, submit, reject, approve by a second person through lifecycle_api, supersede — and
 * the database guards behind them (B2-AT-02..08, 20..37).
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

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

it('drafts a routing with operations, machines, tools and inspections (B2-AT-02, 03, 20)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v] = rtDraft($this, $this->t, $s);

    expect($routing['code'])->toBe('R1')->and($routing['status'])->toBe('ACTIVE');
    expect($v['status'])->toBe('DRAFT')->and($v['versionNo'])->toBe(1)->and($v['standardYield'])->toBe('0.98000')
        ->and($v['operationCount'])->toBe(2);
    $ops = $v['operations'];
    expect(array_column($ops, 'opSeq'))->toBe([10, 20])
        ->and($ops[0]['stdCycleSeconds'])->toBe('4.500')->and($ops[0]['machines'][0]['machineCode'])->toBe('M-200')
        ->and($ops[0]['machines'][0]['isPreferred'])->toBeTrue()->and($ops[0]['tools'][0]['dieCode'])->toBe('D-100')
        ->and($ops[0]['isFinalOperation'])->toBeFalse()->and($ops[1]['isFinalOperation'])->toBeTrue()
        ->and($ops[1]['inspections'])->toBe([['characteristic' => 'Hole position', 'samplingNote' => '1 of 50']]);

    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertOk()->assertHeader('ETag', '"'.$v['version'].'"')
        ->assertJsonPath('data.operations.1.processTypeCode', 'INSP');
    spa($this)->getJson('/api/v1/query/planning.routings')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.itemCode', 'BRK-100');

    // replacing the list needs the version's ETag; the old one is now stale
    spa($this)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => []])->assertStatus(428);
    spa($this)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => []], ['If-Match' => '"1"'])->assertStatus(412);
})->group('integration', 'master');

it('refuses invalid operation lists: duplicate sequence, foreign machine, unlinked die (B2-AT-04, 05, 06)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [, $v] = rtDraft($this, $this->t, $s);
    $put = fn (array $ops) => spa($this)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => $ops], ['If-Match' => '"'.$v['version'].'"']);
    $ops = rtOperations($s);

    $dup = $ops;
    $dup[1]['opSeq'] = 10;
    $put($dup)->assertStatus(422)->assertJsonValidationErrors(['operations.0.opSeq']);

    $foreign = $ops;
    $foreign[0]['machines'] = [['machineId' => $s['m2']['id']]];
    $put($foreign)->assertStatus(422)->assertJsonPath('errors', ['operations.0.machines.0.machineId' => ['Choose a machine of this operation\'s work center, once.']]);

    $unlinked = $ops;
    $unlinked[0]['tools'] = [['dieId' => $s['loose']['id']]];
    $put($unlinked)->assertStatus(422)->assertJsonValidationErrors(['operations.0.tools.0.dieId']);

    $twoPreferred = $ops;
    $twoPreferred[1]['machines'] = [['machineId' => $s['m2']['id'], 'isPreferred' => true]];
    $twoPreferred[0]['machines'][] = ['machineId' => $s['m1']['id'], 'isPreferred' => true];
    $put($twoPreferred)->assertStatus(422);

    // nothing of the refused lists was kept
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertJsonPath('data.operationCount', 2)->assertJsonPath('data.version', $v['version']);

    // the database refuses the same directly
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into routing_operation_machines (tenant_id, operation_id, machine_id) values (?, ?, ?)',
        [$this->t['tenant'], $v['operations'][0]['id'], $s['m2']['id']],
    )))->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into routing_operation_tools (tenant_id, operation_id, die_id) values (?, ?, ?)',
        [$this->t['tenant'], $v['operations'][0]['id'], $s['loose']['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('submits complete versions only; the preparer cannot approve or reject (B2-AT-07, 21, 29)', function () {
    $preparer = orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $routing = spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R1',
        'routingKind' => 'PRIMARY', 'effectiveFrom' => rtDay($this->t)])->assertCreated()->json('data');
    $v = spa($this)->getJson('/api/v1/query/planning.routing_versions?filter[routingId]='.$routing['id'])->json('data.0');
    expect($v['preparedByName'])->not->toBeNull();

    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertStatus(422)
        ->assertJsonValidationErrors(['standardYield', 'operations']);

    $v = patchWith($this, '/api/v1/planning/routing-versions/'.$v['id'], ['standardYield' => 1], $v['version'])->assertOk()->json('data');
    spa($this)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => rtOperations($s)], ['If-Match' => '"'.$v['version'].'"'])->assertOk();
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

    // SUBMITTED content is frozen, also in the database
    patchWith($this, '/api/v1/planning/routing-versions/'.$v['id'], ['standardYield' => 0.5], 99)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        'update routing_operations set std_cycle_seconds = 1 where routing_version_id = ?', [$v['id']],
    )))->toThrow(QueryException::class);

    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/reject', ['reason' => 'mine'])->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    // nor can anyone make it APPROVED without the lifecycle function
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update routing_versions set status = 'APPROVED', approved_by = ?, approved_at = now() where id = ?", [$preparer['id'], $v['id']],
    )))->toThrow(QueryException::class);

    // a PPC planner may prepare and submit, never approve
    rtSwitch($this, $this->t, 'PPC');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
})->group('integration', 'master');

it('approves by a second person, audits it, and supersedes the previous version on revise (B2-AT-08, 22, 23, 30)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v1] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/submit')->assertOk();

    $checker = rtSwitch($this, $this->t, 'CSA');
    $approved = spa($this)->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/approve')->assertOk()->json('data');
    expect($approved['status'])->toBe('APPROVED')->and($approved['approvedByName'])->not->toBeNull()->and($approved['effectiveTo'])->toBeNull();
    spa($this)->getJson('/api/v1/query/planning.routings?filter[id]='.$routing['id'])->assertJsonPath('data.0.currentVersionNo', 1);

    // revise: v2 starts tomorrow, copied from v1
    $v2 = spa($this)->postJson('/api/v1/planning/routings/'.$routing['id'].'/versions', ['effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'Faster cycle'])
        ->assertCreated()->json('data');
    expect($v2['versionNo'])->toBe(2)->and($v2['status'])->toBe('DRAFT')->and($v2['standardYield'])->toBe('0.98000')
        ->and(count($v2['operations']))->toBe(2)->and($v2['operations'][0]['tools'][0]['dieCode'])->toBe('D-100');
    spa($this)->postJson('/api/v1/planning/routings/'.$routing['id'].'/versions', ['effectiveFrom' => rtDay($this->t, 2), 'changeReason' => 'again'])
        ->assertStatus(409); // one open version at a time
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/submit')->assertOk();

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $old = spa($this)->getJson('/api/v1/planning/routing-versions/'.$v1['id'])->assertOk()->json('data');
    expect($old['status'])->toBe('SUPERSEDED')->and($old['effectiveTo'])->toBe(rtDay($this->t));

    $audit = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        "select action, actor from audit_events where entity_type = 'routing_version' and action in ('routing_version.approved', 'routing_version.superseded') order by occurred_at, id",
    ));
    expect(array_column(array_map(fn ($r) => (array) $r, $audit), 'action'))
        ->toBe(['routing_version.approved', 'routing_version.approved', 'routing_version.superseded']);
    expect($audit[0]->actor)->toBe($checker['id']);

    // approved and superseded versions never change again
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update routing_versions set change_reason = 'edit' where id = ?", [$v1['id']],
    )))->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->delete(
        'delete from routing_operations where routing_version_id = ?', [$v2['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('rejects with a reason, reopens and withdraws; never back-dates (B2-AT-25, 26, 27)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v] = rtDraft($this, $this->t, $s);

    spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R2',
        'routingKind' => 'ALTERNATE', 'effectiveFrom' => rtDay($this->t, -1)])->assertStatus(422)->assertJsonValidationErrors(['effectiveFrom']);
    patchWith($this, '/api/v1/planning/routing-versions/'.$v['id'], ['effectiveFrom' => rtDay($this->t, -1)], $v['version'])->assertStatus(422);

    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk();
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/withdraw')->assertOk()->assertJsonPath('data.status', 'DRAFT');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/reopen')->assertStatus(409);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk();

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/reject', ['reason' => ''])->assertStatus(422);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/reject', ['reason' => 'Cycle time too optimistic'])->assertOk()
        ->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.rejectReason', 'Cycle time too optimistic');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/reopen')->assertOk()->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.rejectReason', null);

    // one primary routing per item and plant
    spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R3',
        'routingKind' => 'PRIMARY', 'effectiveFrom' => rtDay($this->t)])->assertStatus(422)->assertJsonValidationErrors(['routingKind']);
    spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R1',
        'routingKind' => 'ALTERNATE', 'effectiveFrom' => rtDay($this->t)])->assertStatus(422)->assertJsonValidationErrors(['routingCode']);
})->group('integration', 'master');

it('re-checks content at approval: a machine moved away after submit blocks approval (B2-AT-28)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [, $v] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk();
    patchWith($this, '/api/v1/core/machines/'.$s['m1']['id'], ['workCenterId' => $s['wc2']['id']], 1)->assertOk();

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertStatus(422)
        ->assertJsonPath('errors.version.0', 'A machine is no longer in its operation\'s work center.');
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertJsonPath('data.status', 'SUBMITTED');
})->group('integration', 'master');

it('keeps routings inside the plant scope and the tenant (B2-AT-31..37)', function () {
    [$p1, $p2] = $this->t['plants'];
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk();

    // an approver bound to plant 2 cannot approve a plant-1 routing — decided inside the lifecycle function too
    rtSwitch($this, $this->t, 'CSA', ['PLANT', $p2]);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    spa($this)->getJson('/api/v1/query/planning.routings')->assertOk()->assertJsonPath('meta.total', 0);
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertForbidden();
    spa($this)->postJson('/api/v1/planning/routings/'.$routing['id'].'/versions', ['effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'x'])->assertForbidden();

    // a viewer sees, never changes
    rtSwitch($this, $this->t, 'PRL', ['PLANT', $p1]);
    spa($this)->getJson('/api/v1/query/planning.routings')->assertOk()->assertJsonPath('meta.total', 1);
    spa($this)->getJson('/api/v1/query/planning.process_types')->assertOk()->assertJsonPath('meta.total', 2);
    spa($this)->postJson('/api/v1/planning/process-types', ['code' => 'X', 'name' => 'x', 'category' => 'HANDLING'])->assertForbidden();

    // another tenant sees nothing and reaches nothing
    $other = TenantFixtures::provision(1);
    AccessFixtures::roles($other);
    rtSwitch($this, $other, 'CSA');
    spa($this)->getJson('/api/v1/query/planning.routings')->assertOk()->assertJsonPath('meta.total', 0);
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertNotFound();
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/approve')->assertNotFound();
    expect(AccessFixtures::run($other, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from routing_versions')->n))->toBe(0);
})->group('integration', 'master');

it('keeps process type codes unique and edits them at their version', function () {
    orgUser($this, $this->t, 'CSA');
    $pt = spa($this)->postJson('/api/v1/planning/process-types', ['code' => 'BEND', 'name' => 'Bending', 'category' => 'TRANSFORMATION'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/planning/process-types', ['code' => 'BEND', 'name' => 'x', 'category' => 'TRANSFORMATION'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A process type with this code already exists.');
    spa($this)->postJson('/api/v1/planning/process-types', ['code' => 'bend', 'name' => 'x', 'category' => 'TRANSFORMATION'])->assertStatus(422);
    patchWith($this, '/api/v1/planning/process-types/'.$pt['id'], ['name' => 'Press bending'], null)->assertStatus(428);
    patchWith($this, '/api/v1/planning/process-types/'.$pt['id'], ['name' => 'Press bending', 'status' => 'INACTIVE'], 1)->assertOk()
        ->assertJsonPath('data.name', 'Press bending')->assertJsonPath('data.status', 'INACTIVE');
    patchWith($this, '/api/v1/planning/process-types/'.$pt['id'], ['name' => 'Again'], 1)->assertStatus(412);
})->group('integration', 'master');

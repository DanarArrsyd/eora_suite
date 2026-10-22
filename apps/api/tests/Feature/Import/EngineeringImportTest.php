<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\CrashingRoutingsTemplate;
use Tests\Support\TenantFixtures;

/*
 * M2 step 6c (doc 29 §1.6, doc 26 §5, D-B2-07, D-079, D-083): routing, BOM and map imports — all-or-nothing, landing as
 * DRAFT versions/revisions that still need a second person's approval; a record with an open version is refused; a
 * commit that no longer passes writes nothing (B2-AT-61, 73).
 */

const ENG_ROUTING_HEADER = ['Plant code', 'Item code', 'Routing code', 'Routing kind', 'Effective from', 'Standard yield %', 'Change reason', 'Op seq', 'Step key',
    'Process type', 'Work center', 'Setup minutes', 'Cycle seconds', 'Cycle basis', 'Parts per cycle', 'Inspection required', 'Count point', 'Machine', 'Die', 'Cavities used'];
const ENG_BOM_HEADER = ['Item code', 'Effective from', 'Change reason', 'Line no', 'Component code', 'Quantity per piece', 'Unit', 'Scrap allowance %', 'Purpose'];
const ENG_MAP_HEADER = ['Plant code', 'Item code', 'BOM version', 'Routing code', 'Routing version', 'Line no', 'Op seq', 'Change reason'];

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('imports');
});

/** @return list<list<string>> the two operations of BRK-100 routing R1 in P1 (rtSetup master data) */
function engOps(array $t, string $reason = ''): array
{
    $day = rtDay($t);

    return [
        ['P1', 'BRK-100', 'R1', 'PRIMARY', $day, '98.5', $reason, '10', 'BLANK', 'BLANK', 'PRS-01', '30', '4.5', 'PER_STROKE', '2', '', '', 'M-200', 'D-100', '2'],
        ['P1', 'BRK-100', 'R1', 'PRIMARY', $day, '98.5', $reason, '20', '', 'INSP', 'WLD-01', '', '12', 'PER_PIECE', '', 'YES', 'NO', '', '', ''],
    ];
}

function engUpload(object $test, array $t, string $template, array $header, array $rows): string
{
    $id = impUpload($test, impFile($header, $rows, "{$template}.xlsx"), $template)->assertCreated()->json('data.id');
    impWork($t, $id, 'validate');

    return $id;
}

function engCommit(object $test, array $t, string $id): stdClass
{
    spa($test)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertOk();
    spa($test)->postJson("/api/v1/exchange/imports/{$id}/actions/commit")->assertOk();
    impWork($t, $id, 'commit');

    return impJob($t, $id);
}

it('imports a routing as a DRAFT version with its operations, machine and die; an open version refuses the next import (D-083)', function () {
    orgUser($this, $this->t, 'CSA');
    rtSetup($this, $this->t);

    // one wrong row stops the whole file: all-or-nothing
    $bad = engOps($this->t);
    $bad[1][17] = 'M-200'; // a press machine on the weld line
    $refused = engUpload($this, $this->t, 'routings', ENG_ROUTING_HEADER, $bad);
    expect(impJob($this->t, $refused))->valid_rows->toBe(1)->error_rows->toBe(1);
    spa($this)->postJson("/api/v1/exchange/imports/{$refused}/actions/submit")->assertStatus(422)
        ->assertJsonPath('errors.job.0', 'This template imports everything or nothing: fix the rows with errors and upload the file again.');

    $job = engCommit($this, $this->t, engUpload($this, $this->t, 'routings', ENG_ROUTING_HEADER, engOps($this->t)));
    expect($job)->status->toBe('COMMITTED')->committed_rows->toBe(2);
    $v = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select v.status, v.version_no, v.standard_yield::text as sy, (select count(*) from routing_operations o where o.routing_version_id = v.id) as ops,
                (select count(*) from routing_operation_machines m join routing_operations o on o.id = m.operation_id where o.routing_version_id = v.id and m.is_preferred) as machines,
                (select count(*) from routing_operation_tools t join routing_operations o on o.id = t.operation_id where o.routing_version_id = v.id) as tools
           from routing_versions v join routings r on r.id = v.routing_id where r.routing_code = 'R1'",
    ));
    expect($v)->status->toBe('DRAFT')->version_no->toBe(1)->sy->toBe('0.98500')->ops->toBe(2)->machines->toBe(1)->tools->toBe(1);

    // the version is still open: a second import of R1 is refused until it is finished or withdrawn
    $again = engUpload($this, $this->t, 'routings', ENG_ROUTING_HEADER, engOps($this->t, 'Faster press'));
    expect(spa($this)->getJson("/api/v1/exchange/imports/{$again}/rows?status=ERROR")->json('data.0.errors'))->toContain('Routing R1 has an open version 1. Finish or withdraw it first.');
})->group('integration', 'import');

it('imports a BOM as a DRAFT version; a repeated component needs a purpose; version rows must agree', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $kg = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'KG'")->id);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-1', 'name' => 'Coil', 'itemType' => 'RAW', 'baseUomId' => $kg])->assertCreated();
    spa($this)->postJson('/api/v1/core/items', ['code' => 'NUT-M6', 'name' => 'Nut', 'itemType' => 'RAW', 'baseUomId' => $s['item']['baseUomId']])->assertCreated();
    $day = rtDay($this->t);

    $bad = engUpload($this, $this->t, 'boms', ENG_BOM_HEADER, [
        ['BRK-100', $day, '', '10', 'COIL-1', '0.485', 'KG', '2', ''],
        ['BRK-100', $day, '', '20', 'NUT-M6', '2', 'PCS', '', ''],
        ['BRK-100', $day, '', '30', 'NUT-M6', '2', 'PCS', '', 'flange'],
        ['BRK-100', rtDay($this->t, 1), '', '40', 'COIL-1', '0.01', 'KG', '', 'setup'],
    ]);
    $errors = array_merge(...array_column(spa($this)->getJson("/api/v1/exchange/imports/{$bad}/rows?status=ERROR")->json('data'), 'errors'));
    expect($errors)->toContain('BOM of BRK-100: Effective from, Change reason must be the same as on row 2.')
        ->toContain('This component is on more than one line: say what this line is for, e.g. "bracket side".');

    $job = engCommit($this, $this->t, engUpload($this, $this->t, 'boms', ENG_BOM_HEADER, [
        ['BRK-100', $day, 'First BOM', '10', 'COIL-1', '0.485', 'KG', '2', ''],
        ['BRK-100', $day, 'First BOM', '20', 'NUT-M6', '2', 'PCS', '', 'bracket side'],
        ['BRK-100', $day, 'First BOM', '30', 'NUT-M6', '2', 'PCS', '', 'flange side'],
    ]));
    expect($job)->status->toBe('COMMITTED')->committed_rows->toBe(3);
    $v = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select v.status, (select scrap_allowance::text from bom_lines l where l.bom_version_id = v.id and l.line_no = 10) as scrap from bom_versions v',
    ));
    expect($v)->status->toBe('DRAFT')->scrap->toBe('0.02000');
})->group('integration', 'import');

it('imports a map only on submitted versions, every BOM line once, as a DRAFT revision (D-079)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $kg = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'KG'")->id);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-1', 'name' => 'Coil', 'itemType' => 'RAW', 'baseUomId' => $kg])->assertCreated();
    engCommit($this, $this->t, engUpload($this, $this->t, 'routings', ENG_ROUTING_HEADER, engOps($this->t)));
    engCommit($this, $this->t, engUpload($this, $this->t, 'boms', ENG_BOM_HEADER, [
        ['BRK-100', rtDay($this->t), '', '10', 'COIL-1', '0.485', 'KG', '', 'blank'],
        ['BRK-100', rtDay($this->t), '', '20', 'COIL-1', '0.010', 'KG', '', 'setup'],
    ]));
    $mapRows = [['P1', 'BRK-100', '1', 'R1', '1', '10', '10', ''], ['P1', 'BRK-100', '1', 'R1', '1', '20', '20', '']];

    $early = engUpload($this, $this->t, 'bom_routing_maps', ENG_MAP_HEADER, $mapRows);
    expect(spa($this)->getJson("/api/v1/exchange/imports/{$early}/rows?status=ERROR")->json('data.0.errors'))
        ->toBe(['Submit the BOM version and the routing version first; a map is drawn on content that no longer changes.']);
    spa($this)->postJson("/api/v1/exchange/imports/{$early}/actions/reject", ['reason' => 'Versions not submitted yet'])->assertOk();

    foreach (AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select('select id from routing_versions')) as $rv) {
        spa($this)->postJson("/api/v1/planning/routing-versions/{$rv->id}/actions/submit")->assertOk();
    }
    foreach (AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select('select id from bom_versions')) as $bv) {
        spa($this)->postJson("/api/v1/planning/bom-versions/{$bv->id}/actions/submit")->assertOk();
    }
    $gap = engUpload($this, $this->t, 'bom_routing_maps', ENG_MAP_HEADER, [$mapRows[0]]);
    expect(spa($this)->getJson("/api/v1/exchange/imports/{$gap}/rows?status=ERROR")->json('data.0.errors'))->toBe(['Every BOM line needs an operation; missing lines 20.']);

    $job = engCommit($this, $this->t, engUpload($this, $this->t, 'bom_routing_maps', ENG_MAP_HEADER, $mapRows));
    expect($job)->status->toBe('COMMITTED')->committed_rows->toBe(2);
    $map = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select status, map_revision, (select count(*) from bom_routing_map_lines) as lines from bom_routing_maps'));
    expect($map)->status->toBe('DRAFT')->map_revision->toBe(1)->lines->toBe(2);
    expect($s['plant'])->toBeString();
})->group('integration', 'import');

it('writes nothing when rows no longer pass at commit, and the retry commits everything once (B2-AT-61, 73)', function () {
    config(['eora.import_templates.crash_routings' => CrashingRoutingsTemplate::class]);
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $file = impFile(ENG_ROUTING_HEADER, engOps($this->t), 'routings.xlsx');

    // the weld line is deactivated between preview and commit: the whole job is refused, nothing written
    $first = impUpload($this, impAgain($file), 'crash_routings')->assertCreated()->json('data.id');
    impWork($this->t, $first, 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$first}/actions/submit")->assertOk();
    $wc2 = spa($this)->getJson('/api/v1/query/core.work_centers?filter[id]='.$s['wc2']['id'])->json('data.0');
    patchWith($this, '/api/v1/core/work-centers/'.$s['wc2']['id'], ['status' => 'INACTIVE'], $wc2['version'])->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$first}/actions/commit")->assertOk();
    impWork($this->t, $first, 'commit');
    expect(impJob($this->t, $first))->status->toBe('FAILED')->failure_code->toBe('COMMIT_REFUSED')->error_file_key->not->toBeNull();
    $count = fn () => (int) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select (select count(*) from routings) + (select count(*) from routing_operations) as n')->n);
    expect($count())->toBe(0);

    // the worker dies inside the commit after writing everything: the transaction rolls back — still nothing
    $wc2 = spa($this)->getJson('/api/v1/query/core.work_centers?filter[id]='.$s['wc2']['id'])->json('data.0');
    patchWith($this, '/api/v1/core/work-centers/'.$s['wc2']['id'], ['status' => 'ACTIVE'], $wc2['version'])->assertOk();
    $retry = impUpload($this, impAgain($file), 'crash_routings')->assertCreated()->json('data');
    expect($retry['retryOfJobId'])->toBe($first);
    impWork($this->t, $retry['id'], 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/commit")->assertOk();
    config(['eora.test.crash_routings' => true]);
    expect(fn () => impWork($this->t, $retry['id'], 'commit'))->toThrow(RuntimeException::class, 'worker killed');
    expect($count())->toBe(0);

    // the redelivered message takes over after the lease and commits every row exactly once
    config(['eora.test.crash_routings' => false]);
    impExpireLease($this->t, $retry['id']);
    impWork($this->t, $retry['id'], 'commit', 'w2');
    expect(impJob($this->t, $retry['id']))->status->toBe('COMMITTED')->committed_rows->toBe(2);
    expect($count())->toBe(3) // one routing + two operations
        ->and((int) AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from import_source_rows')->n))->toBe(2);
})->group('integration', 'import');

<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportLease;
use App\Platform\Import\LostLease;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 exit (doc 29 §6): the B2 acceptance scenarios of doc 26 §10 that had no test naming them — B2-AT-01, 11, 12, 13,
 * 14, 18, 38, 39, 41 and 59 (AT-40 is the "unlinked die" case of RoutingTest).
 */

const GAP_ROUTING_HEADER = ['Plant code', 'Item code', 'Routing code', 'Routing kind', 'Effective from', 'Standard yield %', 'Change reason', 'Op seq', 'Step key',
    'Process type', 'Work center', 'Setup minutes', 'Cycle seconds', 'Cycle basis', 'Parts per cycle', 'Inspection required', 'Count point', 'Machine', 'Die', 'Cavities used'];
const GAP_MAPPING_HEADER = ['Customer code', 'Customer part no', 'Item code', 'Customer drawing rev', 'Effective from'];

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('imports');
});

/** @return list<string> every error message of the job's rows */
function gapErrors(object $test, string $job): array
{
    return array_merge(...array_column(spa($test)->getJson("/api/v1/exchange/imports/{$job}/rows?status=ERROR")->json('data'), 'errors'));
}

/** One routing row of the import template: a single BLANK operation on PRS-01 (rtSetup) unless overridden. */
function gapRoutingRow(array $t, string $item, array $over = []): array
{
    $row = ['Plant code' => 'P1', 'Item code' => $item, 'Routing code' => 'R1', 'Routing kind' => 'PRIMARY', 'Effective from' => rtDay($t), 'Standard yield %' => '98.5',
        'Op seq' => '10', 'Process type' => 'BLANK', 'Work center' => 'PRS-01', 'Cycle seconds' => '4', 'Cycle basis' => 'PER_STROKE', 'Parts per cycle' => '1'];
    $row = array_merge($row, $over);

    return array_map(static fn (string $h) => $row[$h] ?? '', GAP_ROUTING_HEADER);
}

it('imports 1,278 items and their customer part mappings for four customers, listing every duplicate and unknown reference per row (B2-AT-01)', function () {
    orgUser($this, $this->t, 'CSA');
    foreach (['C1', 'C2', 'C3', 'C4'] as $code) {
        spa($this)->postJson('/api/v1/core/customers', ['code' => $code, 'name' => "Customer {$code}"])->assertCreated();
    }

    $items = [...impItems(1278), ['PART-0007', 'Twice', 'FINISHED', 'PCS', '', '', '', '', ''], ['PART-9999', 'Odd unit', 'FINISHED', 'BOX', '', '', '', '', '']];
    $job = impJob($this->t, impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, $items)));
    expect($job)->committed_rows->toBe(1278)->error_rows->toBe(2);
    expect(gapErrors($this, $job->id))->toBe(['Item PART-0007 is also on row 8 of this file.', 'Unit BOX does not exist. Import or create it first.']);

    $today = rtDay($this->t);
    $mappings = array_map(static fn (int $i) => ['C'.(($i % 4) + 1), "CP-{$i}", sprintf('PART-%04d', $i), '', $today], range(1, 1278));
    $mappings[] = ['C9', 'CP-X', 'PART-0001', '', $today];
    $mappings[] = ['C1', 'CP-Y', 'PART-9999', '', $today];
    $job = impJob($this->t, impThrough($this, $this->t, impFile(GAP_MAPPING_HEADER, $mappings, 'customer-items.xlsx'), 'customer_items'));
    expect($job)->committed_rows->toBe(1278)->error_rows->toBe(2);
    expect(gapErrors($this, $job->id))->toBe(['Customer C9 does not exist.', 'Item PART-9999 does not exist.']);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select count(*) as n, count(distinct customer_id) as customers from customer_items',
    )))->n->toBe(1278)->customers->toBe(4);
})->group('integration', 'import');

it('has no fixed maximum: a larger synthetic item set commits in full (B2-AT-01)', function () {
    orgUser($this, $this->t, 'CSA');
    expect(impJob($this->t, impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(4000)))))->committed_rows->toBe(4000)->error_rows->toBe(0);
})->group('integration', 'import');

it('answers 404 for another tenant\'s routing, item and work center, records access.not_found, and treats its codes as unknown (B2-AT-11)', function () {
    $b = TenantFixtures::provision(1);
    AccessFixtures::roles($b);
    orgUser($this, $b, 'CSA');
    $sb = rtSetup($this, $b);
    [$routing, $v] = rtDraft($this, $b, $sb);

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v['id'])->assertNotFound();
    patchWith($this, '/api/v1/planning/routings/'.$routing['id'], ['status' => 'INACTIVE'], 1)->assertNotFound();
    patchWith($this, '/api/v1/core/items/'.$sb['item']['id'], ['name' => 'Taken'], 1)->assertNotFound();
    patchWith($this, '/api/v1/core/work-centers/'.$sb['wc']['id'], ['name' => 'Taken'], 1)->assertNotFound();
    spa($this)->getJson('/api/v1/query/planning.routings?q=BRK-100')->assertOk()->assertJsonPath('meta.total', 0);
    expect(array_count_values(eventActions($this->t))['access.not_found'] ?? 0)->toBe(4);

    // tenant B's codes are unknown here
    spa($this)->postJson('/api/v1/core/customers', ['code' => 'C1', 'name' => 'Customer'])->assertCreated();
    $id = impUpload($this, impFile(GAP_MAPPING_HEADER, [['C1', 'CP-1', 'BRK-100', '', rtDay($this->t)]], 'customer-items.xlsx'), 'customer_items')->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    expect(gapErrors($this, $id))->toBe(['Item BRK-100 does not exist.']);
})->group('integration', 'master');

it('finds routings by an operation\'s process, filters by plant, work center and status, sorts by item and pages consistently (B2-AT-12)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    rtDraft($this, $this->t, $s); // BRK-100: BLANK on PRS-01, Final inspection on WLD-01
    impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(120)));
    $rows = array_map(fn (int $i) => gapRoutingRow($this->t, sprintf('PART-%04d', $i)), range(1, 120));
    $job = impJob($this->t, impThrough($this, $this->t, impFile(GAP_ROUTING_HEADER, $rows, 'routings.xlsx'), 'routings'));
    expect($job)->status->toBe('COMMITTED')->committed_rows->toBe(120);

    $list = fn (string $query) => spa($this)->getJson('/api/v1/query/planning.routings?'.$query)->assertOk();
    $list('q=blank')->assertJsonPath('meta.total', 121);
    $list('q=inspect')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.itemCode', 'BRK-100');
    $list('filter[workCenterId]='.$s['wc2']['id'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.itemCode', 'BRK-100');
    $list('filter[plantId]='.$this->t['plants'][1])->assertJsonPath('meta.total', 0);
    $list('filter[status]=ACTIVE&filter[plantId]='.$this->t['plants'][0])->assertJsonPath('meta.total', 121);

    $page = $list('q=blank&sort=itemCode&page[number]=3&page[size]=50');
    $page->assertJsonPath('meta.total', 121)->assertJsonCount(21, 'data');
    expect($page->json('data.0.itemCode'))->toBe('PART-0100')->and($page->json('data.20.itemCode'))->toBe('PART-0120')
        ->and(array_key_exists('operationProcess', $page->json('data.0')))->toBeFalse(); // a search-only field is not listed

    // the export of the same list is accepted with the same filter, search and sort
    spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'planning.routings', 'filter' => ['workCenterId' => $s['wc2']['id']], 'q' => 'blank', 'sort' => 'itemCode'])
        ->assertCreated();
})->group('integration', 'master');

it('returns the previous job when the same routing file is uploaded again, without a second version (B2-AT-13)', function () {
    orgUser($this, $this->t, 'CSA');
    rtSetup($this, $this->t);
    $file = impFile(GAP_ROUTING_HEADER, [gapRoutingRow($this->t, 'BRK-100')], 'routings.xlsx');
    $id = impThrough($this, $this->t, impAgain($file), 'routings');

    impUpload($this, impAgain($file), 'routings')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'COMMITTED');
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from routing_versions')))->n->toBe(1);
})->group('integration', 'import');

it('writes one audit row with actor, correlation id and before/after hashes for create, submit, approve, supersede and import commit (B2-AT-14)', function () {
    $maker = orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v1] = rtDraft($this, $this->t, $s);
    spa($this)->withHeader('X-Request-Id', 'req-submit-v1')->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/submit')->assertOk();
    $checker = rtSwitch($this, $this->t, 'CSA');
    spa($this)->withHeader('X-Request-Id', 'req-approve-v1')->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/approve')->assertOk();
    $v2 = spa($this)->postJson('/api/v1/planning/routings/'.$routing['id'].'/versions', ['effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'Faster'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/submit')->assertOk();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->withHeader('X-Request-Id', 'req-approve-v2')->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/approve')->assertOk();
    $job = impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(1)));

    $audit = fn (string $action, string $entity) => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        'select actor, correlation_id, before_hash, after_hash from audit_events where action = ? and entity_id = ?', [$action, $entity],
    ));
    $created = $audit('routing.created', $routing['id']);
    $submitted = $audit('routing_version.submitted', $v1['id']);
    $approved = $audit('routing_version.approved', $v1['id']);
    $superseded = $audit('routing_version.superseded', $v1['id']);
    $committed = $audit('import_job.committed', $job);
    foreach ([$created, $submitted, $approved, $superseded, $committed] as $rows) {
        expect($rows)->toHaveCount(1)->and($rows[0]->actor)->not->toBeNull()->and($rows[0]->correlation_id)->not->toBeNull()
            ->and($rows[0]->after_hash)->toMatch('/^[0-9a-f]{64}$/');
    }
    expect($created[0]->actor)->toBe($maker['id'])->and($created[0]->before_hash)->toBeNull();
    expect($submitted[0]->correlation_id)->toBe('req-submit-v1')->and($submitted[0]->before_hash)->toMatch('/^[0-9a-f]{64}$/');
    // written by the lifecycle function: the trusted actor and the request's correlation id (D-B2-14)
    expect($approved[0]->actor)->toBe($checker['id'])->and($approved[0]->correlation_id)->toBe('req-approve-v1')->and($approved[0]->before_hash)->not->toBeNull();
    expect($superseded[0]->correlation_id)->toBe('req-approve-v2')->and($superseded[0]->before_hash)->not->toBe($superseded[0]->after_hash);
})->group('integration', 'master');

it('retries a routing file that failed on a missing work center once it exists, keeping the failed job and its error file (B2-AT-18)', function () {
    orgUser($this, $this->t, 'CSA');
    rtSetup($this, $this->t);
    $file = impFile(GAP_ROUTING_HEADER, [gapRoutingRow($this->t, 'BRK-100', ['Work center' => 'WC-WELD-2'])], 'routings.xlsx');
    $failed = impUpload($this, impAgain($file), 'routings')->assertCreated()->json('data.id');
    impWork($this->t, $failed, 'validate');
    expect(gapErrors($this, $failed))->toHaveCount(1)->and(gapErrors($this, $failed)[0])->toContain('WC-WELD-2');
    // all-or-nothing: the file cannot be committed; the reviewer rejects it
    spa($this)->postJson("/api/v1/exchange/imports/{$failed}/actions/submit")->assertStatus(422);
    spa($this)->postJson("/api/v1/exchange/imports/{$failed}/actions/reject", ['reason' => 'Work center missing'])->assertOk();

    spa($this)->postJson('/api/v1/core/work-centers', ['plantId' => $this->t['plants'][0], 'code' => 'WC-WELD-2', 'name' => 'Weld 2', 'workCenterType' => 'PRESS'])->assertCreated();
    $retry = impUpload($this, impAgain($file), 'routings')->assertCreated()->assertJsonPath('data.retryOfJobId', $failed)->json('data.id');
    impWork($this->t, $retry, 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$retry}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$retry}/actions/commit")->assertOk();
    impWork($this->t, $retry, 'commit');
    expect(impJob($this->t, $retry))->status->toBe('COMMITTED')->committed_rows->toBe(1);

    // the failed job and its error file stay in history; a third upload returns the committed result
    spa($this)->getJson("/api/v1/exchange/imports/{$failed}")->assertOk()->assertJsonPath('data.status', 'REJECTED');
    spa($this)->get("/api/v1/exchange/imports/{$failed}/error-file")->assertOk();
    impUpload($this, impAgain($file), 'routings')->assertOk()->assertJsonPath('data.id', $retry);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from routing_versions')))->n->toBe(1);
})->group('integration', 'import');

it('refuses a die of another tenant by id or code (B2-AT-38)', function () {
    $b = TenantFixtures::provision(1);
    AccessFixtures::roles($b);
    orgUser($this, $b, 'CSA');
    $foreign = spa($this)->postJson('/api/v1/core/dies', ['plantId' => $b['plants'][0], 'code' => 'D-900', 'name' => 'Foreign die', 'dieType' => 'DIE'])->assertCreated()->json('data');

    rtSwitch($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [, $v] = rtDraft($this, $this->t, $s);
    patchWith($this, '/api/v1/core/dies/'.$foreign['id'], ['name' => 'Taken'], 1)->assertNotFound();
    $ops = rtOperations($s);
    $ops[0]['tools'] = [['dieId' => $foreign['id']]];
    spa($this)->putJson('/api/v1/planning/routing-versions/'.$v['id'].'/operations', ['operations' => $ops], ['If-Match' => '"'.$v['version'].'"'])
        ->assertStatus(422)->assertJsonValidationErrors(['operations.0.tools.0.dieId']);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into routing_operation_tools (tenant_id, operation_id, die_id) values (?, ?, ?)', [$this->t['tenant'], $v['operations'][0]['id'], $foreign['id']],
    )))->toThrow(QueryException::class);

    $id = impUpload($this, impFile(GAP_ROUTING_HEADER, [gapRoutingRow($this->t, 'BRK-100', ['Routing code' => 'R2', 'Routing kind' => 'ALTERNATE', 'Die' => 'D-900'])], 'routings.xlsx'), 'routings')
        ->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    expect(implode(' ', gapErrors($this, $id)))->toContain('D-900');
})->group('integration', 'master');

it('flags a die moved to another plant on the approved version and refuses to approve a version that still uses it (B2-AT-39)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$routing, $v1] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/submit')->assertOk();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v1['id'].'/actions/approve')->assertOk();
    $v2 = spa($this)->postJson('/api/v1/planning/routings/'.$routing['id'].'/versions', ['effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'Faster'])->assertCreated()->json('data');
    expect($v2['operations'][0]['tools'][0])->dieCode->toBe('D-100')->availableInPlant->toBeTrue();
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/submit')->assertOk();

    // D-100 moves to plant 2 by an audited master-data update
    $die = spa($this)->getJson('/api/v1/query/core.dies?filter[id]='.$s['die']['id'])->json('data.0');
    patchWith($this, '/api/v1/core/dies/'.$s['die']['id'], ['plantId' => $this->t['plants'][1]], $die['version'])->assertOk();
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) as n from audit_events where action = 'die.updated' and entity_id = ?", [$s['die']['id']],
    )))->n->toBe(1);

    // v1 stays readable and says so; v2, which still uses the die, cannot be approved
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v1['id'])->assertOk()->assertJsonPath('data.status', 'APPROVED')
        ->assertJsonPath('data.operations.0.tools.0.availableInPlant', false);
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v2['id'].'/actions/approve')->assertStatus(422);
    spa($this)->getJson('/api/v1/planning/routing-versions/'.$v2['id'])->assertJsonPath('data.status', 'SUBMITTED');
})->group('integration', 'master');

it('refuses a routing import that uses a die of another plant, committing nothing of it (B2-AT-41)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $p2 = spa($this)->postJson('/api/v1/core/dies', ['plantId' => $this->t['plants'][1], 'code' => 'D-P2', 'name' => 'Plant 2 die', 'dieType' => 'DIE'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/core/die-items', ['dieId' => $p2['id'], 'itemId' => $s['item']['id'], 'partsPerStroke' => 1])->assertCreated();

    $rows = [gapRoutingRow($this->t, 'BRK-100', ['Die' => 'D-P2']), gapRoutingRow($this->t, 'BRK-100', ['Op seq' => '20', 'Process type' => 'INSP', 'Work center' => 'WLD-01', 'Cycle basis' => 'PER_PIECE'])];
    $id = impUpload($this, impFile(GAP_ROUTING_HEADER, $rows, 'routings.xlsx'), 'routings')->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    expect(impJob($this->t, $id))->error_rows->toBe(1);
    expect(implode(' ', gapErrors($this, $id)))->toContain('D-P2');
    spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertStatus(422);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from routings')))->n->toBe(0);
})->group('integration', 'import');

it('makes a takeover wait for the chunk a worker is committing; that chunk lands once and the worker\'s next chunk is refused (B2-AT-59)', function () {
    orgUser($this, $this->t, 'CSA');
    $id = impUpload($this, impFile(IMP_ITEM_HEADER, impItems(2)))->assertCreated()->json('data.id');
    $context = TenantContext::forTenant($this->t['tenant'], (string) impJob($this->t, $id)->created_by_user);
    $tx = app(TenantTransaction::class);
    config(['database.connections.pgsql_takeover' => config('database.connections.pgsql')]);
    $claim = fn (string $connection, string $worker) => $tx->run($context, function (EoraPostgresConnection $db) use ($id, $worker) {
        $db->statement("set local lock_timeout = '300ms'");

        return ImportLease::claim($db, $id, ['RESERVED', 'VALIDATING'], 'VALIDATING', $worker);
    }, $connection);

    $w1 = $claim('pgsql', 'w1');
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update import_jobs set lease_until = now() + interval '1 second' where id = ?", [$id]));
    // W1 is inside a chunk: it passed its fence (row lock held) and its lease runs out while it writes
    $tx->run($context, function (EoraPostgresConnection $db) use ($w1, $id, $claim) {
        $w1->fence($db, 'VALIDATING');
        usleep(1_300_000);
        expect(fn () => $claim('pgsql_takeover', 'w2'))->toThrow(QueryException::class, 'lock timeout'); // the takeover waits
        $db->insert("insert into import_rows (tenant_id, job_id, row_no, status, data) values ((select c.tenant from ctx_api.context() c), ?, 1, 'VALID', '{}')", [$id]);
    });

    $w2 = $claim('pgsql_takeover', 'w2');
    expect($w2->generation)->toBe($w1->generation + 1);
    expect(fn () => $tx->run($context, fn (EoraPostgresConnection $db) => $w1->fence($db, 'VALIDATING')))->toThrow(LostLease::class);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from import_rows where job_id = ?', [$id])))->n->toBe(1);
})->group('integration', 'import');

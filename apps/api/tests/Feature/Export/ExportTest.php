<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Export\ExportColumn;
use App\Platform\Export\ExportExpiry;
use App\Platform\Export\ExportProcessor;
use App\Platform\Export\ProcessExport;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 7b (doc 08 §Secure export, doc 24 §3, D-092): a list is exported as a queued job running the list's own
 * QueryDefinition as the requester; the workbook carries every list column with the screen's headings and typed
 * cells; the signed link lasts 15 minutes and works only in the requester's session; the file is deleted after
 * 24 hours; at most 100,000 rows.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('exports');
});

/** Export jobs are visible to their requester only: tests read them as the system actor (as the file expiry does). */
function expAsSystem(array $t, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::system($t['tenant']), $work, 'pgsql_provisioner');
}

/** The requester's own inbox rows (inbox RLS: the owner only). */
function expInbox(array $t, string $user, string $kind): ?stdClass
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $user), fn (EoraPostgresConnection $db) => $db->selectOne(
        'select kind, title, body, deep_link from inbox_notifications where kind = ?', [$kind]), 'pgsql_provisioner');
}

function expJob(array $t, string $id): stdClass
{
    return expAsSystem($t, fn (EoraPostgresConnection $db) => $db->selectOne('select * from export_jobs where id = ?', [$id]));
}

/** Runs the queued export in-process as the requester; returns the job row. */
function expRun(array $t, string $id): stdClass
{
    app(ExportProcessor::class)->run($t['tenant'], $id, (string) expJob($t, $id)->requested_by_user);

    return expJob($t, $id);
}

/** @return array<string, list<list<mixed>>> sheet name => rows of raw cell values */
function expSheets(string $key): array
{
    $path = tempnam(sys_get_temp_dir(), 'exp-test-').'.xlsx';
    file_put_contents($path, Storage::disk('exports')->get($key));
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(static fn ($c) => $c->getValue(), $row->cells);
        }
    }
    $reader->close();

    return $sheets;
}

it('gives every exportable master list export columns that read only its own fields', function () {
    $exportable = [];
    foreach ((array) config('eora.queries') as $resource => $class) {
        /** @var QueryDefinition $definition */
        $definition = app($class);
        $keys = array_map(static fn (Field $f) => $f->key, $definition->fields());
        foreach ($definition->exportColumns() as $column) {
            expect($column)->toBeInstanceOf(ExportColumn::class);
            if (is_string($column->value)) {
                expect($keys)->toContain($column->value);
            }
        }
        if ($definition->exportColumns() !== []) {
            $exportable[] = $resource;
            expect($definition->title())->not->toBe($resource);
        }
    }
    expect($exportable)->toHaveCount(19)->toContain('core.items', 'core.customer_items', 'planning.routings', 'planning.conversion_profiles')
        ->not->toContain('exchange.import_jobs', 'planning.routing_versions');
})->group('integration', 'export');

it('exports the item list as the list shows it: filter, search and sort, typed cells, escaped formulas, an About sheet', function () {
    orgUser($this, $this->t, 'CSA');
    $pcs = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'PCS'")->id);
    foreach ([['BRK-200', 'Bracket rear', 'FINISHED'], ['BRK-100', '=HYPERLINK("x")', 'FINISHED'], ['COIL-1', 'Coil', 'RAW']] as [$code, $name, $type]) {
        spa($this)->postJson('/api/v1/core/items', ['code' => $code, 'name' => $name, 'itemType' => $type, 'baseUomId' => $pcs, 'lotControlled' => false])->assertCreated();
    }

    $job = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.items', 'filter' => ['itemType' => 'FINISHED'], 'q' => 'brk', 'sort' => '-code'])
        ->assertCreated()->assertJsonPath('data.status', 'QUEUED')->assertJsonPath('data.title', 'Items')->json('data');
    Queue::assertPushed(ProcessExport::class, fn (ProcessExport $m) => $m->jobId === $job['id']);

    $done = expRun($this->t, $job['id']);
    expect($done)->status->toBe('DONE')->row_count->toBe(2)->file_name->toStartWith('items-');
    $sheets = expSheets((string) $done->file_key);
    expect($sheets['Items'][0])->toBe(['Code', 'Name', 'Type', 'Base unit', 'Drawing', 'Drawing rev', 'Lot controlled', 'Inspection required', 'Incoming release required', 'Status']);
    // the list's own order (-code) and filter; screen labels; a cell that would start a formula stays text
    expect($sheets['Items'][1])->toBe(['BRK-200', 'Bracket rear', 'Finished good', 'PCS', '', '', 'No', 'No', 'No', 'Active']);
    expect($sheets['Items'][2][1])->toBe("'=HYPERLINK(\"x\")");
    expect(array_column($sheets['About'], 1))->toContain('Items', '2', 'brk', 'itemType: FINISHED', 'code (descending)');
    expect(end($sheets['About'])[1])->toStartWith('Generated by EORA Suite | Exported on ');

    // the requester is told, with a link to the export
    $note = expInbox($this->t, (string) $done->requested_by_user, 'exchange.export_ready');
    expect($note)->title->toBe('Export ready')->deep_link->toBe("/system/exports?job={$job['id']}");
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select("select action from audit_events where entity_type = 'export_job' order by occurred_at"), 'action')))
        ->toBe(['export_job.requested', 'export_job.completed']);
})->group('integration', 'export');

it('writes dates as dates and numbers as numbers', function () {
    orgUser($this, $this->t, 'CSA');
    $pcs = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'SHEET'")->id);
    $item = spa($this)->postJson('/api/v1/core/items', ['code' => 'SHT-1', 'name' => 'Sheet', 'itemType' => 'RAW', 'baseUomId' => $pcs])->assertCreated()->json('data');
    $kg = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'KG'")->id);
    spa($this)->postJson('/api/v1/core/uom-conversions', ['itemId' => $item['id'], 'fromUomId' => $pcs, 'toUomId' => $kg, 'factor' => 37.33, 'effectiveFrom' => rtDay($this->t)])->assertCreated();

    $id = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.uom_conversions'])->assertCreated()->json('data.id');
    $sheets = expSheets((string) expRun($this->t, $id)->file_key);
    $row = $sheets['Unit Conversions'][1];
    expect($row[3])->toBe(37.33)->and($row[5])->toBeInstanceOf(DateTimeImmutable::class)->and($row[5]->format('Y-m-d'))->toBe(rtDay($this->t))->and($row[7])->toBe('In effect');
})->group('integration', 'export');

it('hands the file out through a signed 15-minute link in the requester\'s session only, and deletes it after 24 hours', function () {
    $owner = orgUser($this, $this->t, 'CSA');
    $id = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.plants'])->assertCreated()->json('data.id');
    spa($this)->postJson("/api/v1/exchange/exports/{$id}/link")->assertStatus(409); // not written yet
    expRun($this->t, $id);

    $link = spa($this)->postJson("/api/v1/exchange/exports/{$id}/link")->assertOk()->json('data');
    expect($link['url'])->toStartWith("/api/v1/exchange/exports/{$id}/file?expires=")->toContain('signature=');
    spa($this)->get($link['url'])->assertOk()->assertDownload();
    spa($this)->get(str_replace('signature=', 'signature=0', $link['url']))->assertForbidden();
    $this->travel(16)->minutes();
    spa($this)->get($link['url'])->assertForbidden();
    $this->travelBack();

    // another member of the tenant cannot see, link or fetch it
    orgUser($this, $this->t, 'CSA');
    spa($this)->getJson("/api/v1/exchange/exports/{$id}")->assertNotFound();
    spa($this)->postJson("/api/v1/exchange/exports/{$id}/link")->assertNotFound();
    signIn($this, $owner, $this->t);

    // 24 hours later the file is gone and the job says so
    expAsSystem($this->t, fn (EoraPostgresConnection $db) => $db->update("update export_jobs set expires_at = now() - interval '1 minute' where id = ?", [$id]));
    spa($this)->postJson("/api/v1/exchange/exports/{$id}/link")->assertStatus(409)
        ->assertJsonPath('detail', 'This export was deleted 24 hours after it was written. Export the list again.');
    $key = (string) expJob($this->t, $id)->file_key;
    expect(app(ExportExpiry::class)->expire($this->t['tenant']))->toBe(1)->and(Artisan::call('eora:exports:expire'))->toBe(0);
    Storage::disk('exports')->assertMissing($key);
    spa($this)->getJson("/api/v1/exchange/exports/{$id}")->assertJsonPath('data.status', 'EXPIRED');
})->group('integration', 'export');

it('refuses more than the row limit up front, and again when the list grew before the worker ran', function () {
    orgUser($this, $this->t, 'CSA');
    config(['eora.export.max_rows' => 2]);
    spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.uoms'])->assertStatus(422)
        ->assertJsonPath('errors.query.0', 'This list has 3 rows; an export holds at most 2. Narrow the filter or the search.');

    $id = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.uoms', 'q' => 'kg'])->assertCreated()->json('data.id');
    config(['eora.export.max_rows' => 0]);
    expect(expRun($this->t, $id))->status->toBe('FAILED')->failure_code->toBe('TOO_MANY_ROWS');
    expect(expInbox($this->t, (string) expJob($this->t, $id)->requested_by_user, 'exchange.export_failed'))->title->toBe('Export failed');
})->group('integration', 'export');

it('exports only what the requester may see, checks the permissions again when the job runs, and refuses lists that cannot be exported', function () {
    [$p1] = $this->t['plants'];
    $admin = orgUser($this, $this->t, 'CSA');
    foreach ($this->t['plants'] as $i => $plant) {
        spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $plant, 'code' => 'WH'.($i + 1), 'name' => 'Store', 'warehouseType' => 'GENERAL'])->assertCreated();
    }
    spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'exchange.import_jobs'])->assertStatus(422)->assertJsonPath('errors.resource.0', 'This list cannot be exported.');
    spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.nothing'])->assertNotFound();

    // a plant-scoped member exports the warehouses of the plant only — like the list
    $planner = member($this, $this->t);
    $assignment = AccessFixtures::grant($this->t, $planner['id'], 'INV', ['PLANT', $p1]);
    signIn($this, $planner, $this->t);
    $id = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.warehouses'])->assertCreated()->json('data.id');
    expect(expSheets((string) expRun($this->t, $id)->file_key)['Warehouses'])->toHaveCount(2);

    // the role is gone by the time the worker runs: nothing is written
    $again = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.warehouses'])->assertCreated()->json('data.id');
    AccessFixtures::revoke($this->t, $assignment);
    expect(expRun($this->t, $again))->status->toBe('FAILED')->failure_code->toBe('NOT_ALLOWED');

    // without the export permission there is no export at all
    $viewer = member($this, $this->t);
    AccessFixtures::grant($this->t, $viewer['id'], 'OPR', ['TENANT']);
    signIn($this, $viewer, $this->t);
    spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'core.warehouses'])->assertForbidden();
    expect($admin)->toBeArray();
})->group('integration', 'export');

it('exports routings with the version in use and the open version of each row', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $s['item']['id'], 'routingCode' => 'R1', 'routingKind' => 'PRIMARY', 'effectiveFrom' => rtDay($this->t)])->assertCreated();

    $id = spa($this)->postJson('/api/v1/exchange/exports', ['resource' => 'planning.routings'])->assertCreated()->json('data.id');
    $rows = expSheets((string) expRun($this->t, $id)->file_key)['Routings'];
    expect($rows[0])->toBe(['Item', 'Item name', 'Routing', 'Routing kind', 'Plant', 'Version in use', 'In use from', 'Standard yield %', 'Operations', 'Open version', 'Open version status', 'Status']);
    expect($rows[1])->toBe(['BRK-100', 'Bracket', 'R1', 'Primary', 'P1', '', '', '', '', 1, 'Draft', 'Active']);
})->group('integration', 'export');

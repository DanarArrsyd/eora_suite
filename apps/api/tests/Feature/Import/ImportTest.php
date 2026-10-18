<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportLease;
use App\Platform\Import\ImportProcessor;
use App\Platform\Import\LeaseBusy;
use App\Platform\Import\LostLease;
use App\Platform\Import\ProcessImport;
use App\Platform\Import\XlsxFile;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\CrashingItemsTemplate;
use Tests\Support\TenantFixtures;

/*
 * M2 step 6a (doc 29 §1.6, doc 26 §5, ADR-0017, D-081): the import pipeline — upload checks, atomic reservation per file,
 * validation into staging with an error file, submit and commit by a poster, worker leases with fencing, and retries that
 * never commit a source row twice (B2-AT-47..52, 58, 60, 62, 69..72). The worker steps run here in-process; the queue is
 * faked so each step happens when the test says.
 */

const IMP_ITEM_HEADER = ['Code', 'Name', 'Item type', 'Base unit', 'Lot controlled', 'Inspection required', 'Incoming release required', 'Drawing no', 'Drawing rev'];

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('imports');
});

/** @param  list<list<string>>  $rows */
function impFile(array $header, array $rows, string $name = 'items.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp-test-').'.xlsx';
    XlsxFile::write($path, $header, $rows);

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** The same bytes again (a re-upload of one file: the writer stamps the time, so a rewrite would be another file). */
function impAgain(UploadedFile $file): UploadedFile
{
    $copy = tempnam(sys_get_temp_dir(), 'imp-again-').'.xlsx';
    copy($file->getRealPath(), $copy);

    return new UploadedFile($copy, $file->getClientOriginalName(), null, null, true);
}

/** @return list<list<string>> items PART-0001.. in PCS */
function impItems(int $count, int $from = 1): array
{
    return array_map(static fn (int $i) => [sprintf('PART-%04d', $i), "Part {$i}", 'FINISHED', 'PCS', '', '', '', '', ''], range($from, $from + $count - 1));
}

function impUpload(object $test, UploadedFile $file, string $template = 'items')
{
    return spa($test)->post('/api/v1/exchange/imports', ['template' => $template, 'file' => $file]);
}

function impJob(array $t, string $id): stdClass
{
    return AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select * from import_jobs where id = ?', [$id]));
}

/** Runs one worker step in-process as the user the step acts for. */
function impWork(array $t, string $id, string $phase, string $worker = 'w1'): void
{
    $job = impJob($t, $id);
    $p = app(ImportProcessor::class);
    $phase === 'commit'
        ? $p->commit($t['tenant'], $id, (string) $job->committed_by, $worker)
        : $p->validate($t['tenant'], $id, (string) $job->created_by_user, $worker);
}

/** Upload → validate → submit → commit (request) → commit (worker); returns the job id. */
function impThrough(object $test, array $t, UploadedFile $file, string $template = 'items'): string
{
    $id = impUpload($test, $file, $template)->assertCreated()->json('data.id');
    impWork($t, $id, 'validate');
    spa($test)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertOk();
    spa($test)->postJson("/api/v1/exchange/imports/{$id}/actions/commit")->assertOk()->assertJsonPath('data.status', 'COMMITTING');
    impWork($t, $id, 'commit');

    return $id;
}

function impExpireLease(array $t, string $id): void
{
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update("update import_jobs set lease_until = now() - interval '1 minute' where id = ?", [$id]));
}

it('refuses files that are not plain xlsx workbooks before storing anything', function () {
    orgUser($this, $this->t, 'CSA');
    $text = UploadedFile::fake()->createWithContent('items.xlsx', 'code,name');
    impUpload($this, $text)->assertStatus(422)->assertJsonValidationErrors(['file']);

    $withZip = function (string $entry): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'imp-zip-').'.xlsx';
        XlsxFile::write($path, ['Code'], []);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString($entry, 'x');
        $zip->close();

        return new UploadedFile($path, 'items.xlsx', null, null, true);
    };
    impUpload($this, $withZip('xl/vbaProject.bin'))->assertStatus(422)->assertJsonPath('errors.file.0', 'Workbooks with macros are not accepted. Save it as .xlsx without macros.');
    impUpload($this, $withZip('xl/externalLinks/externalLink1.xml'))->assertStatus(422)->assertJsonValidationErrors(['file']);
    impUpload($this, impFile(IMP_ITEM_HEADER, impItems(1)), 'nope')->assertStatus(422)->assertJsonValidationErrors(['template']);
    expect(Storage::disk('imports')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
})->group('integration', 'import');

it('imports items: validation with an error file, submit, commit by a poster, audit per row (D-081)', function () {
    orgUser($this, $this->t, 'CSA');
    spa($this)->getJson('/api/v1/exchange/import-templates')->assertOk()->assertJsonPath('data.1.code', 'items')->assertJsonPath('data.1.columns.3.header', 'Base unit');
    $rows = [...impItems(3), ['PART-0002', 'Duplicate', 'FINISHED', 'PCS', '', '', '', '', ''], ['bad code', 'x', 'WIDGET', 'BOX', 'maybe', '', '', '', 'B']];
    $job = impUpload($this, impFile(IMP_ITEM_HEADER, $rows))->assertCreated()->assertJsonPath('data.status', 'RESERVED')->json('data');
    Queue::assertPushed(ProcessImport::class, fn (ProcessImport $m) => $m->jobId === $job['id'] && $m->phase === 'validate');

    impWork($this->t, $job['id'], 'validate');
    $shown = spa($this)->getJson("/api/v1/exchange/imports/{$job['id']}")->assertOk()->json('data');
    expect($shown)->toMatchArray(['status' => 'VALIDATED', 'totalRows' => 5, 'validRows' => 3, 'errorRows' => 2, 'hasErrorFile' => true]);
    $errors = spa($this)->getJson("/api/v1/exchange/imports/{$job['id']}/rows?status=ERROR")->assertOk()->json('data');
    expect($errors[0]['rowNo'])->toBe(5)->and($errors[0]['errors'])->toBe(['Item PART-0002 is also on row 3 of this file.'])
        ->and($errors[1]['errors'])->toContain('Item type: use one of RAW, SEMI_FINISHED, FINISHED, SCRAP, CONSUMABLE.', 'Unit BOX does not exist. Import or create it first.', 'Drawing rev needs a drawing no.');
    spa($this)->get("/api/v1/exchange/imports/{$job['id']}/error-file")->assertOk()->assertDownload('eora-items-errors.xlsx');

    // PPC uploads and submits, but only a poster commits
    rtSwitch($this, $this->t, 'PPC');
    spa($this)->postJson("/api/v1/exchange/imports/{$job['id']}/actions/submit")->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    spa($this)->postJson("/api/v1/exchange/imports/{$job['id']}/actions/commit")->assertForbidden();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/exchange/imports/{$job['id']}/actions/commit")->assertOk()->assertJsonPath('data.status', 'COMMITTING');
    Queue::assertPushed(ProcessImport::class, fn (ProcessImport $m) => $m->jobId === $job['id'] && $m->phase === 'commit');
    impWork($this->t, $job['id'], 'commit');

    $done = spa($this)->getJson("/api/v1/exchange/imports/{$job['id']}")->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'COMMITTED', 'committedRows' => 3, 'errorRows' => 2]);
    $made = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select("select code, lot_controlled from items where code like 'PART-%' order by code"));
    expect(array_column(array_map(fn ($r) => (array) $r, $made), 'code'))->toBe(['PART-0001', 'PART-0002', 'PART-0003'])->and($made[0]->lot_controlled)->toBeTrue();
    $audits = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) filter (where action = 'item.created') as items, count(*) filter (where action = 'import_job.committed') as jobs from audit_events",
    ));
    expect((int) $audits->items)->toBe(3)->and((int) $audits->jobs)->toBe(1);
})->group('integration', 'import');

it('fails a file whose header does not match the template version', function () {
    orgUser($this, $this->t, 'CSA');
    $id = impUpload($this, impFile(['Code', 'Name', 'Type'], [['A', 'B', 'C']]))->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    spa($this)->getJson("/api/v1/exchange/imports/{$id}")->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.failureCode', 'HEADER_MISMATCH')
        ->assertJsonPath('data.failureDetail', 'Missing columns: item type, base unit, lot controlled, inspection required, incoming release required, drawing no, drawing rev. Unknown columns: type. Use the Items template, version 1.');
})->group('integration', 'import');

it('reserves one job per file: identical uploads share it while active or committed; a rejected one is retried (B2-AT-47..50)', function () {
    orgUser($this, $this->t, 'CSA');
    $file = impFile(IMP_ITEM_HEADER, impItems(2));
    $first = impUpload($this, impAgain($file))->assertCreated()->json('data.id');
    impUpload($this, impAgain($file))->assertOk()->assertJsonPath('data.id', $first); // RESERVED (AT-47)
    impWork($this->t, $first, 'validate');
    impUpload($this, impAgain($file))->assertOk()->assertJsonPath('data.id', $first); // VALIDATED (AT-48)
    Queue::assertPushed(ProcessImport::class, 1);

    // the database itself refuses a second active job for the same file (concurrent uploads wait on this index)
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into import_jobs (tenant_id, id, template, template_version, file_sha256, file_name, file_size, storage_key, root_job_id, created_by_user)
         select tenant_id, x.id, template, template_version, file_sha256, file_name, file_size, storage_key, x.id, created_by_user
           from import_jobs, (select uuidv7() as id) x where import_jobs.id = ?', [$first],
    )))->toThrow(QueryException::class);

    spa($this)->postJson("/api/v1/exchange/imports/{$first}/actions/reject", ['reason' => 'Wrong plant'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    $retry = impUpload($this, impAgain($file))->assertCreated()->json('data'); // AT-50
    expect($retry['id'])->not->toBe($first)->and($retry['retryOfJobId'])->toBe($first)->and($retry['rootJobId'])->toBe($first);
    spa($this)->getJson("/api/v1/exchange/imports/{$first}")->assertOk()->assertJsonPath('data.status', 'REJECTED'); // history kept

    impWork($this->t, $retry['id'], 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/commit")->assertOk();
    impWork($this->t, $retry['id'], 'commit');
    impUpload($this, impAgain($file))->assertOk()->assertJsonPath('data.id', $retry['id'])->assertJsonPath('data.status', 'COMMITTED'); // AT-49
})->group('integration', 'import');

it('keeps the same file of two tenants apart (B2-AT-52)', function () {
    $t2 = TenantFixtures::provision(1);
    AccessFixtures::roles($t2);
    orgUser($this, $this->t, 'CSA');
    $file = impFile(IMP_ITEM_HEADER, impItems(1));
    $a = impUpload($this, impAgain($file))->assertCreated()->json('data.id');
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();
    orgUser($this, $t2, 'CSA');
    $b = impUpload($this, impAgain($file))->assertCreated()->json('data.id');
    expect($b)->not->toBe($a);
    spa($this)->getJson("/api/v1/exchange/imports/{$a}")->assertNotFound();
})->group('integration', 'import');

it('fences workers: a takeover after an expired lease bumps the generation and the paused worker can no longer write (B2-AT-58, 60)', function () {
    orgUser($this, $this->t, 'CSA');
    $file = impFile(IMP_ITEM_HEADER, impItems(2));
    $id = impUpload($this, impAgain($file))->assertCreated()->json('data.id');
    $context = TenantContext::forTenant($this->t['tenant'], (string) impJob($this->t, $id)->created_by_user);
    $tx = app(TenantTransaction::class);

    $w1 = $tx->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $id, ['RESERVED', 'VALIDATING'], 'VALIDATING', 'w1'));
    expect($tx->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $id, ['RESERVED', 'VALIDATING'], 'VALIDATING', 'w2')))->toBeNull(); // live lease
    impExpireLease($this->t, $id);
    $w2 = $tx->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $id, ['RESERVED', 'VALIDATING'], 'VALIDATING', 'w2'));
    expect($w2->generation)->toBe($w1->generation + 1);
    expect(fn () => $tx->run($context, fn (EoraPostgresConnection $db) => $w1->fence($db, 'VALIDATING')))->toThrow(LostLease::class);
    $tx->run($context, fn (EoraPostgresConnection $db) => $w2->fence($db, 'VALIDATING')); // the owner passes

    // an abandoned job seen by anyone becomes FAILED (WORKER_LOST); the identical file then gets a new job (AT-51/60)
    impExpireLease($this->t, $id);
    spa($this)->getJson("/api/v1/exchange/imports/{$id}")->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.failureCode', 'WORKER_LOST');
    expect(fn () => $tx->run($context, fn (EoraPostgresConnection $db) => $w2->fence($db, 'VALIDATING')))->toThrow(LostLease::class);
    impUpload($this, impAgain($file))->assertCreated()->assertJsonPath('data.retryOfJobId', $id);
})->group('integration', 'import');

it('resumes after a crash between chunks without committing a chunk twice (B2-AT-62)', function () {
    config(['eora.import_templates.crash_items' => CrashingItemsTemplate::class, 'eora.test.crash_at_code' => 'PART-0501']);
    orgUser($this, $this->t, 'CSA');
    $id = impUpload($this, impFile(IMP_ITEM_HEADER, impItems(700)), 'crash_items')->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/commit")->assertOk();
    expect(fn () => impWork($this->t, $id, 'commit'))->toThrow(RuntimeException::class, 'worker killed'); // dies in chunk 2

    // the redelivered message takes the job over once the lease expired and finishes from the staged rows
    config(['eora.test.crash_at_code' => null]);
    impExpireLease($this->t, $id);
    impWork($this->t, $id, 'commit', 'w2');
    $state = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select (select status from import_jobs where id = ?) as status, (select count(*) from import_job_chunks where job_id = ?) as chunks,
                (select count(*) from import_source_rows where committed_by_job_id = ?) as rows, (select count(*) from items where code like 'PART-%') as items",
        [$id, $id, $id],
    ));
    expect($state->status)->toBe('COMMITTED')->and((int) $state->chunks)->toBe(2)->and((int) $state->rows)->toBe(700)->and((int) $state->items)->toBe(700);
})->group('integration', 'import');

it('retries a dead job: committed rows are skipped, rows fixed since are committed once (B2-AT-69..72)', function () {
    config(['eora.import_templates.crash_items' => CrashingItemsTemplate::class, 'eora.test.crash_at_code' => 'PART-1001']);
    orgUser($this, $this->t, 'CSA');
    $rows = impItems(2000);
    $rows[1499][3] = 'BOX'; // row 1501 of the sheet: unknown unit in the first job
    $file = impFile(IMP_ITEM_HEADER, $rows);

    $first = impUpload($this, impAgain($file), 'crash_items')->assertCreated()->json('data.id');
    impWork($this->t, $first, 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$first}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$first}/actions/commit")->assertOk();
    expect(fn () => impWork($this->t, $first, 'commit'))->toThrow(RuntimeException::class); // chunks 1–2 (rows 1–1,000) committed, then death
    impExpireLease($this->t, $first);
    spa($this)->getJson("/api/v1/exchange/imports/{$first}")->assertOk()->assertJsonPath('data.failureCode', 'WORKER_LOST');

    // meanwhile: an imported item is edited on screen (M2-D6 reading of AT-70) and the missing unit is created (AT-71)
    $p5 = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id, version from items where code = 'PART-0005'"));
    patchWith($this, "/api/v1/core/items/{$p5->id}", ['name' => 'Renamed on screen'], (int) $p5->version)->assertOk();
    spa($this)->postJson('/api/v1/core/uoms', ['code' => 'BOX', 'name' => 'Box', 'uomClass' => 'COUNT', 'decimals' => 0])->assertCreated();
    config(['eora.test.crash_at_code' => null]);

    $retry = impUpload($this, impAgain($file), 'crash_items')->assertCreated()->json('data');
    expect($retry['retryOfJobId'])->toBe($first)->and($retry['rootJobId'])->toBe($first);
    impWork($this->t, $retry['id'], 'validate');
    $preview = spa($this)->getJson("/api/v1/exchange/imports/{$retry['id']}")->json('data');
    expect($preview)->toMatchArray(['skippedRows' => 1000, 'validRows' => 1000, 'errorRows' => 0]);
    expect(spa($this)->getJson("/api/v1/exchange/imports/{$retry['id']}/rows?status=SKIPPED")->json('data.0.errors'))->toBe(["Already imported by job {$first}."]);
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$retry['id']}/actions/commit")->assertOk();
    impWork($this->t, $retry['id'], 'commit');

    $state = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select (select count(*) from items where code like 'PART-%') as items, (select name from items where code = 'PART-0005') as renamed,
                (select count(*) from import_source_rows where root_job_id = ?) as ledger,
                (select committed_by_job_id from import_source_rows where root_job_id = ? and row_no = 1501) as box_row",
        [$first, $first],
    ));
    expect((int) $state->items)->toBe(2000)->and($state->renamed)->toBe('Renamed on screen')->and((int) $state->ledger)->toBe(2000)->and($state->box_row)->toBe($retry['id']);

    // AT-72: even without fencing, a second commit of a source row is refused by the ledger
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into import_source_rows (tenant_id, root_job_id, row_no, committed_by_job_id, claim_generation) values (?, ?, 2, ?, 99)',
        [$this->t['tenant'], $first, $retry['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'import');

it('imports units and material specifications, and serves the empty template of each version', function () {
    orgUser($this, $this->t, 'CSA');
    spa($this)->get('/api/v1/exchange/import-templates/material_specs/file')->assertOk()->assertDownload('eora-material-specs-v1.xlsx');

    $uoms = impThrough($this, $this->t, impFile(['Code', 'Name', 'Unit class', 'Decimals'], [['COIL', 'Coil', 'COUNT', '0'], ['KG', 'Kilogram again', 'MASS', '3'], ['MM', 'Millimetre', 'LENGTH', '7']], 'uoms.xlsx'), 'uoms');
    expect(impJob($this->t, $uoms))->status->toBe('COMMITTED')->committed_rows->toBe(1)->error_rows->toBe(2);

    $item = spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-SPCC', 'name' => 'SPCC coil', 'itemType' => 'RAW',
        'baseUomId' => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'KG'")->id)])->assertCreated()->json('data');
    $specs = impThrough($this, $this->t, impFile(['Item code', 'Grade', 'Thickness mm', 'Width mm', 'Density g/cm3'], [
        ['coil-spcc', 'SPCC', '1.2', '120', '7.85'], ['NOPE', 'SPCC', '1', '', ''], ['COIL-SPCC', '', '', '', ''],
    ], 'specs.xlsx'), 'material_specs');
    expect(impJob($this->t, $specs))->committed_rows->toBe(1)->error_rows->toBe(2);
    spa($this)->getJson('/api/v1/query/core.items?filter[id]='.$item['id'])->assertOk()
        ->assertJsonPath('data.0.specThicknessMm', '1.200')->assertJsonPath('data.0.specDensityGCm3', '7.8500');
})->group('integration', 'import');

it('keeps a redelivered message waiting while another worker holds a live lease', function () {
    orgUser($this, $this->t, 'CSA');
    $id = impUpload($this, impFile(IMP_ITEM_HEADER, impItems(1)))->assertCreated()->json('data.id');
    $context = TenantContext::forTenant($this->t['tenant'], (string) impJob($this->t, $id)->created_by_user);
    app(TenantTransaction::class)->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $id, ['RESERVED'], 'VALIDATING', 'w1'));

    // the second delivery must not be acknowledged as done: the queue releases it until the lease expires
    expect(fn () => impWork($this->t, $id, 'validate', 'w2'))->toThrow(LeaseBusy::class);
    impExpireLease($this->t, $id);
    impWork($this->t, $id, 'validate', 'w2');
    expect(impJob($this->t, $id)->status)->toBe('VALIDATED');
})->group('integration', 'import');

it('rechecks the commit permissions in every chunk and reports rows refused at commit in a fresh error file', function () {
    $csa = orgUser($this, $this->t, 'CSA');
    $id = impUpload($this, impFile(IMP_ITEM_HEADER, [...impItems(2), ['DRW-0', 'Drawing zero', 'FINISHED', 'PCS', '', '', '', '0', 'A']]))->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    expect(impJob($this->t, $id))->error_rows->toBe(0)->error_file_key->toBeNull();
    spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/commit")->assertOk();

    // created on screen after the preview: that row fails at commit and lands in a new error file
    spa($this)->postJson('/api/v1/core/items', ['code' => 'PART-0002', 'name' => 'On screen', 'itemType' => 'FINISHED',
        'baseUomId' => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'PCS'")->id)])->assertCreated();
    impWork($this->t, $id, 'commit');
    $job = impJob($this->t, $id);
    expect($job)->status->toBe('COMMITTED')->committed_rows->toBe(2)->error_rows->toBe(1)->error_file_key->not->toBeNull();
    $drawing = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select drawing_no, drawing_rev from items where code = 'DRW-0'"));
    expect($drawing->drawing_no)->toBe('0')->and($drawing->drawing_rev)->toBe('A'); // "0" is text, not empty

    // a poster who loses the permission stops the commit at the next chunk
    $other = impUpload($this, impFile(IMP_ITEM_HEADER, impItems(2, 10)))->assertCreated()->json('data.id');
    impWork($this->t, $other, 'validate');
    spa($this)->postJson("/api/v1/exchange/imports/{$other}/actions/submit")->assertOk();
    spa($this)->postJson("/api/v1/exchange/imports/{$other}/actions/commit")->assertOk();
    foreach (AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        'select ra.id from role_assignments ra join memberships m on m.id = ra.membership_id where m.user_id = ? and ra.revoked_at is null', [$csa['id']],
    )) as $a) {
        AccessFixtures::revoke($this->t, (string) $a->id);
    }
    impWork($this->t, $other, 'commit');
    expect(impJob($this->t, $other))->status->toBe('FAILED')->failure_code->toBe('NOT_ALLOWED')->committed_rows->toBe(0);
})->group('integration', 'import');

it('refuses material-spec values that do not fit their columns instead of failing the chunk', function () {
    orgUser($this, $this->t, 'CSA');
    $kg = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'KG'")->id);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-A', 'name' => 'Coil A', 'itemType' => 'RAW', 'baseUomId' => $kg])->assertCreated();
    $id = impUpload($this, impFile(['Item code', 'Grade', 'Thickness mm', 'Width mm', 'Density g/cm3'], [['COIL-A', '0', '10000000', '120', '7.85']], 'specs.xlsx'), 'material_specs')
        ->assertCreated()->json('data.id');
    impWork($this->t, $id, 'validate');
    expect(spa($this)->getJson("/api/v1/exchange/imports/{$id}/rows?status=ERROR")->json('data.0.errors'))
        ->toBe(['Thickness mm: enter a number above 0, below 10000000 with at most 3 decimals.']);
})->group('integration', 'import');

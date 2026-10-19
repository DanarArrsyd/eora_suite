<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportProcessor;
use App\Platform\Import\XlsxFile;
use Illuminate\Http\UploadedFile;
use Tests\Support\AccessFixtures;

/* Helpers shared by the import feature tests (doc 29 §1.6, ADR-0017): files, uploads, in-process worker steps. */

const IMP_ITEM_HEADER = ['Code', 'Name', 'Item type', 'Base unit', 'Lot controlled', 'Inspection required', 'Incoming release required', 'Drawing no', 'Drawing rev'];

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

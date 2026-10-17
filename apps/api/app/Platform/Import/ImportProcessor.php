<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use LogicException;
use stdClass;
use Throwable;

/**
 * The worker side of an import job (doc 26 §5, ADR-0017): validation into staging with an error file, then the fenced
 * commit in chunks of 500. Every step is its own tenant transaction as the acting user (RLS applies) and starts with
 * the lease fence; a lost lease stops the worker without writing.
 */
final class ImportProcessor
{
    public const int CHUNK = 500;

    public const string DISK = 'imports';

    public function __construct(
        private readonly TenantTransaction $tx,
        private readonly ImportTemplates $templates,
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
    ) {}

    /** RESERVED (or an abandoned VALIDATING) → VALIDATING → VALIDATED, or FAILED for a file that does not fit. */
    public function validate(string $tenant, string $jobId, string $actor, string $worker): void
    {
        $context = TenantContext::forTenant($tenant, $actor);
        $lease = $this->tx->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $jobId, ['RESERVED', 'VALIDATING'], 'VALIDATING', $worker));
        if ($lease === null) {
            return;
        }
        $job = $this->tx->run($context, fn (EoraPostgresConnection $db) => $db->selectOne('select * from import_jobs where id = ?', [$jobId]));
        $template = $this->templates->get((string) $job->template);
        $source = $this->download((string) $job->storage_key);
        try {
            $this->stage($context, $lease, $job, $template, $source);
        } catch (LostLease) {
            return;
        } finally {
            @unlink($source);
        }
    }

    /** COMMITTING → COMMITTED: valid rows in fenced chunks; rows that turned invalid since validation are reported. */
    public function commit(string $tenant, string $jobId, string $actor, string $worker): void
    {
        $context = TenantContext::forTenant($tenant, $actor);
        $lease = $this->tx->run($context, fn (EoraPostgresConnection $db) => ImportLease::claim($db, $jobId, ['COMMITTING'], 'COMMITTING', $worker));
        if ($lease === null) {
            return;
        }
        try {
            $job = $this->tx->run($context, fn (EoraPostgresConnection $db) => $db->selectOne('select * from import_jobs where id = ?', [$jobId]));
            $template = $this->templates->get((string) $job->template);
            if ($template->mode() !== ImportTemplate::VALID_ROWS) {
                throw new LogicException('All-or-nothing templates commit in step 6c.');
            }
            // the committing user must still hold both permissions (D-081)
            $allowed = $this->tx->run($context, function (EoraPostgresConnection $db) use ($template): bool {
                try {
                    $this->authorizer->requireAnywhere($db, 'exchange.import.post');
                    $this->authorizer->requireAnywhere($db, $template->targetPermission());

                    return true;
                } catch (DomainProblem) {
                    return false;
                }
            });
            if (! $allowed) {
                $this->finish($context, $lease, 'COMMITTING', ['status' => 'FAILED', 'failure_code' => 'NOT_ALLOWED',
                    'failure_detail' => 'The person committing no longer has the permissions to import this template.'], 'import_job.failed');

                return;
            }
            while ($this->commitChunk($context, $lease, $job, $template)) {
                // next chunk
            }
            $this->finish($context, $lease, 'COMMITTING', ['status' => 'COMMITTED', 'committed_at' => now()->toIso8601String()], 'import_job.committed');
        } catch (LostLease) {
            return;
        }
    }

    private function stage(TenantContext $context, ImportLease $lease, stdClass $job, ImportTemplate $template, string $source): void
    {
        $headers = array_map(static fn (ImportColumn $c) => $c->header, $template->columns());
        try {
            $rows = XlsxFile::rows($source);
            $first = $rows->valid() ? $rows->current() : null;
        } catch (Throwable) {
            $this->fail($context, $lease, 'UNREADABLE_FILE', 'The workbook could not be read. Save it again as .xlsx and upload it.');

            return;
        }
        $found = $first === null ? [] : $first[1];
        $missing = array_values(array_diff(array_map('strtolower', $headers), array_map('strtolower', $found)));
        $extra = array_values(array_filter(array_diff(array_map('strtolower', $found), array_map('strtolower', $headers)), static fn (string $h) => $h !== ''));
        if ($missing !== [] || $extra !== []) {
            $this->fail($context, $lease, 'HEADER_MISMATCH', trim(
                ($missing !== [] ? 'Missing columns: '.implode(', ', $missing).'. ' : '').
                ($extra !== [] ? 'Unknown columns: '.implode(', ', $extra).'. ' : '').
                sprintf('Use the %s template, version %d.', $template->title(), $template->version()),
            ));

            return;
        }
        // column position of each template header in this file (order may differ)
        $position = [];
        foreach ($headers as $header) {
            $position[$header] = (int) array_search(strtolower($header), array_map('strtolower', $found), true);
        }
        $rows->next();

        $committed = $this->tx->run($context, function (EoraPostgresConnection $db) use ($lease, $job): array {
            $lease->fence($db, 'VALIDATING');
            $db->delete('delete from import_rows where job_id = ?', [$job->id]);
            $done = [];
            foreach ($db->select('select row_no, committed_by_job_id from import_source_rows where root_job_id = ?', [$job->root_job_id]) as $r) {
                $done[(int) $r->row_no] = (string) $r->committed_by_job_id;
            }

            return $done;
        });

        $seen = [];
        $batch = [];
        try {
            while ($rows->valid()) {
                [$rowNo, $values] = $rows->current();
                $row = [];
                foreach ($position as $header => $at) {
                    $row[$header] = $values[$at] ?? '';
                }
                $batch[] = [$rowNo, $row];
                if (count($batch) >= self::CHUNK) {
                    $this->stageBatch($context, $lease, $job, $template, $committed, $batch, $seen);
                    $batch = [];
                }
                $rows->next();
            }
        } catch (LostLease $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail($context, $lease, 'UNREADABLE_FILE', 'The workbook could not be read to the end. Save it again as .xlsx and upload it.');

            return;
        }
        $this->stageBatch($context, $lease, $job, $template, $committed, $batch, $seen);

        $errorKey = $this->errorFile($context, $job, $headers);
        $this->finish($context, $lease, 'VALIDATING', ['status' => 'VALIDATED', 'error_file_key' => $errorKey], 'import_job.validated');
    }

    /**
     * Validates and stages one batch of rows in its own fenced transaction; rows committed by an earlier job of the same
     * file are staged as SKIPPED.
     *
     * @param  array<int, string>  $committed  row number => job that committed it
     * @param  list<array{0: int, 1: array<string, string>}>  $batch
     * @param  array<string, array<string, int>>  $seen
     */
    private function stageBatch(TenantContext $context, ImportLease $lease, stdClass $job, ImportTemplate $template, array $committed, array $batch, array &$seen): void
    {
        if ($batch === []) {
            return;
        }
        $this->tx->run($context, function (EoraPostgresConnection $db) use (&$seen, $batch, $lease, $job, $template, $committed): void {
            $lease->fence($db, 'VALIDATING');
            $lease->heartbeat($db);
            foreach ($batch as [$rowNo, $row]) {
                if (isset($committed[$rowNo])) {
                    $status = 'SKIPPED';
                    $errors = ["Already imported by job {$committed[$rowNo]}."];
                } else {
                    $errors = $template->validate($db, $row, $rowNo, $seen);
                    $status = $errors === [] ? 'VALID' : 'ERROR';
                }
                $db->insert(
                    'insert into import_rows (tenant_id, job_id, row_no, data, status, errors) values ((select c.tenant from ctx_api.context() c), ?, ?, ?::jsonb, ?, ?::jsonb)',
                    [$job->id, $rowNo, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $status, json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                );
            }
        });
    }

    /** Commits the next chunk of VALID rows; false when none is left. */
    private function commitChunk(TenantContext $context, ImportLease $lease, stdClass $job, ImportTemplate $template): bool
    {
        return $this->tx->run($context, function (EoraPostgresConnection $db) use ($lease, $job, $template): bool {
            $lease->fence($db, 'COMMITTING');
            $lease->heartbeat($db);
            $rows = $db->select("select row_no, data from import_rows where job_id = ? and status = 'VALID' order by row_no limit ".self::CHUNK, [$job->id]);
            if ($rows === []) {
                return false;
            }
            $chunk = (int) $db->selectOne('select coalesce(max(chunk_no), 0) + 1 as n from import_job_chunks where job_id = ?', [$job->id])->n;
            foreach ($rows as $r) {
                $rowNo = (int) $r->row_no;
                /** @var array<string, string> $data */
                $data = json_decode((string) $r->data, true, flags: JSON_THROW_ON_ERROR);
                $earlier = $db->selectOne('select committed_by_job_id from import_source_rows where root_job_id = ? and row_no = ?', [$job->root_job_id, $rowNo]);
                if ($earlier !== null) {
                    $this->markRow($db, $job, $rowNo, 'SKIPPED', ["Already imported by job {$earlier->committed_by_job_id}."]);

                    continue;
                }
                $seen = [];
                $errors = $template->validate($db, $data, $rowNo, $seen);
                $id = null;
                if ($errors === []) {
                    try {
                        // savepoint: a row the database refuses (e.g. created on screen meanwhile) fails alone
                        $id = $db->transaction(fn () => $template->commit($db, $data));
                    } catch (QueryException $e) {
                        if ($e->getCode() !== '23505') {
                            throw $e;
                        }
                        $errors = ['It already exists (created after the file was checked).'];
                    }
                }
                if ($id === null) {
                    $this->markRow($db, $job, $rowNo, 'ERROR', $errors);

                    continue;
                }
                // a second commit of the same source row aborts the chunk (UNIQUE root_job_id, row_no)
                $db->insert(
                    'insert into import_source_rows (tenant_id, root_job_id, row_no, committed_by_job_id, claim_generation, entity_ref)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?)',
                    [$job->root_job_id, $rowNo, $job->id, $lease->generation, $id],
                );
                $db->update("update import_rows set status = 'COMMITTED', entity_ref = ? where job_id = ? and row_no = ?", [$id, $job->id, $rowNo]);
            }
            $db->insert(
                'insert into import_job_chunks (tenant_id, job_id, chunk_no, claim_generation) values ((select c.tenant from ctx_api.context() c), ?, ?, ?)',
                [$job->id, $chunk, $lease->generation],
            );

            return true;
        });
    }

    /** @param  list<string>  $errors */
    private function markRow(EoraPostgresConnection $db, stdClass $job, int $rowNo, string $status, array $errors): void
    {
        $db->update('update import_rows set status = ?, errors = ?::jsonb where job_id = ? and row_no = ?', [$status, json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $job->id, $rowNo]);
    }

    /**
     * Ends the worker's part: counts from staging, the given changes, lock released, audited.
     *
     * @param  array<string, mixed>  $changes  column => value (names are code)
     */
    private function finish(TenantContext $context, ImportLease $lease, string $status, array $changes, string $action): void
    {
        $this->tx->run($context, function (EoraPostgresConnection $db) use ($lease, $status, $changes, $action): void {
            $lease->fence($db, $status);
            $counts = $db->selectOne(
                "select count(*) as total, count(*) filter (where status = 'VALID') as valid, count(*) filter (where status = 'ERROR') as error,
                        count(*) filter (where status = 'SKIPPED') as skipped, count(*) filter (where status = 'COMMITTED') as committed
                   from import_rows where job_id = ?",
                [$lease->jobId],
            );
            $before = (array) $db->selectOne('select * from import_jobs where id = ?', [$lease->jobId]);
            $set = ['total_rows' => (int) $counts->total, 'valid_rows' => (int) $counts->valid, 'error_rows' => (int) $counts->error,
                'skipped_rows' => (int) $counts->skipped, 'committed_rows' => (int) $counts->committed, 'locked_by' => null, 'lease_until' => null] + $changes;
            $sql = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($set)));
            $db->update("update import_jobs set {$sql} where id = ?", [...array_values($set), $lease->jobId]);
            $after = (array) $db->selectOne('select * from import_jobs where id = ?', [$lease->jobId]);
            $this->audit->record($db, $action, 'import_job', $lease->jobId, $before, $after, [
                'rows' => (int) $counts->total, 'valid' => (int) $counts->valid, 'errors' => (int) $counts->error, 'committed' => (int) $counts->committed,
            ]);
        });
    }

    private function fail(TenantContext $context, ImportLease $lease, string $code, string $detail): void
    {
        $this->finish($context, $lease, 'VALIDATING', ['status' => 'FAILED', 'failure_code' => $code, 'failure_detail' => $detail], 'import_job.failed');
    }

    /**
     * The error rows with their messages as a workbook next to the source file; null when every row is fine.
     *
     * @param  list<string>  $headers
     */
    private function errorFile(TenantContext $context, stdClass $job, array $headers): ?string
    {
        $rows = $this->tx->run($context, fn (EoraPostgresConnection $db) => $db->select(
            "select row_no, data, errors from import_rows where job_id = ? and status = 'ERROR' order by row_no", [$job->id],
        ));
        if ($rows === []) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'eora-errors-').'.xlsx';
        try {
            XlsxFile::write($path, ['Row', ...$headers, 'Errors'], (function () use ($rows, $headers) {
                foreach ($rows as $r) {
                    /** @var array<string, string> $data */
                    $data = json_decode((string) $r->data, true, flags: JSON_THROW_ON_ERROR);
                    /** @var list<string> $errors */
                    $errors = json_decode((string) $r->errors, true, flags: JSON_THROW_ON_ERROR);
                    yield [(string) $r->row_no, ...array_map(static fn (string $h) => $data[$h] ?? '', $headers), implode(' ', $errors)];
                }
            })());
            $key = dirname((string) $job->storage_key).'/errors.xlsx';
            $stream = fopen($path, 'rb') ?: throw new LogicException('The error file could not be read back.');
            Storage::disk(self::DISK)->writeStream($key, $stream);
            fclose($stream);

            return $key;
        } finally {
            @unlink($path);
        }
    }

    private function download(string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eora-import-');
        $in = Storage::disk(self::DISK)->readStream($key) ?? throw new LogicException("Import file {$key} is missing.");
        $out = fopen($path, 'wb') ?: throw new LogicException('No temporary file for the import.');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $path;
    }
}

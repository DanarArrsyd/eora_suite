<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data exchange → Import (doc 29 §1.6, doc 26 §5, doc 08, ADR-0017, D-081). Upload reserves one job per (template,
 * template version, file) atomically and queues its validation; the preview shows every row with its result and an
 * error file; a job is submitted for commit and committed by someone holding `exchange.import.post` and the template's
 * master permission. Valid rows commit in fenced chunks in the worker. Every job stays in history.
 */
final class ImportController
{
    use MasterWrites;

    private const string CREATE = 'exchange.import.create';

    private const string SUBMIT = 'exchange.import.submit';

    private const string POST = 'exchange.import.post';

    private const array ACTIVE = ['RESERVED', 'VALIDATING', 'VALIDATED', 'SUBMITTED', 'COMMITTING', 'COMMITTED'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly ImportTemplates $templates,
    ) {}

    /** The templates the tenant can import, with their current version and columns. */
    public function templates(): JsonResponse
    {
        $this->authorizer->requireAnywhere($this->db(), self::CREATE);

        return response()->json(['data' => array_map(static fn (ImportTemplate $t) => [
            'code' => $t->code(), 'version' => $t->version(), 'title' => $t->title(), 'mode' => $t->mode(), 'permission' => $t->targetPermission(),
            'columns' => array_map(static fn (ImportColumn $c) => ['header' => $c->header, 'required' => $c->required, 'hint' => $c->hint], $t->columns()),
        ], $this->templates->all())]);
    }

    /** The empty template workbook (header row of the current version). */
    public function templateFile(string $template): BinaryFileResponse
    {
        $this->authorizer->requireAnywhere($this->db(), self::CREATE);
        $t = $this->templates->get($template);
        $path = tempnam(sys_get_temp_dir(), 'eora-template-').'.xlsx';
        XlsxFile::write($path, array_map(static fn (ImportColumn $c) => $c->header, $t->columns()), []);

        return response()->download($path, sprintf('eora-%s-v%d.xlsx', str_replace('_', '-', $t->code()), $t->version()))->deleteFileAfterSend();
    }

    /**
     * Upload a filled template (multipart `template`, `file`). The same file for the same template version returns the
     * job it already has while that job is active or committed (200); a file whose last job failed or was rejected gets a
     * new job that retries it (`retryOfJobId`), skipping rows already committed. A new job is validated in the worker.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'template' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $template = $this->templates->get((string) $request->input('template'));
        $file = $request->file('file');
        $path = (string) $file->getRealPath();
        UploadInspector::check($path, (int) $file->getSize());
        $sha = (string) hash_file('sha256', $path);
        $name = mb_substr(basename((string) $file->getClientOriginalName()) ?: 'upload.xlsx', 0, 255);
        $key = [$template->code(), $template->version(), $sha];

        $existing = $this->activeJob($db, ...$key);
        if ($existing !== null) {
            return $this->respond($db, new ImportJobsQuery, (string) $existing->id);
        }
        $previous = $db->selectOne(
            "select id, root_job_id from import_jobs where template = ? and template_version = ? and file_sha256 = ? and status in ('FAILED', 'REJECTED')
              order by created_at desc limit 1",
            $key,
        );
        $id = (string) $db->selectOne('select uuidv7()::text as id')->id;
        $storage = sprintf('tenant/%s/imports/%s/source.xlsx', (string) $db->selectOne('select c.tenant from ctx_api.context() c')->tenant, $id);
        $inserted = $db->selectOne(
            'insert into import_jobs (tenant_id, id, template, template_version, file_sha256, file_name, file_size, storage_key, retry_of_job_id, root_job_id, created_by_user)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, (select c.actor from ctx_api.context() c))
             on conflict (tenant_id, template, template_version, file_sha256)
               where status in (\'RESERVED\', \'VALIDATING\', \'VALIDATED\', \'SUBMITTED\', \'COMMITTING\', \'COMMITTED\') do nothing
             returning id',
            [$id, ...$key, $name, (int) $file->getSize(), $storage, $previous?->id, $previous !== null ? $previous->root_job_id : $id],
        );
        if ($inserted === null) {
            // an identical upload reserved the job first (B2-AT-47)
            return $this->respond($db, new ImportJobsQuery, (string) $this->activeJob($db, ...$key)?->id);
        }
        $stream = fopen($path, 'rb') ?: throw new \RuntimeException('The upload could not be read.');
        Storage::disk(ImportProcessor::DISK)->writeStream($storage, $stream);
        fclose($stream);
        $this->audit->record($db, 'import_job.reserved', 'import_job', $id, null, $this->state($db, 'import_jobs', $id),
            ['template' => $template->code(), 'templateVersion' => $template->version(), 'retryOf' => $previous?->id]);
        $this->dispatch($db, $id, 'validate');

        return $this->respond($db, new ImportJobsQuery, $id, 201);
    }

    /**
     * One job. A job whose worker's lease expired reads as FAILED (WORKER_LOST) — recorded as such by the next upload or
     * worker claim (reads do not write); a job no worker picked up within two minutes is queued again.
     */
    public function show(string $job): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'import_jobs', $job);
        $stale = $db->selectOne(
            "select status from import_jobs where id = ? and status in ('RESERVED', 'COMMITTING') and locked_by is null and updated_at < now() - interval '2 minutes'",
            [$id],
        );
        if ($stale !== null) {
            $this->dispatch($db, $id, $stale->status === 'RESERVED' ? 'validate' : 'commit');
        }

        return $this->respond($db, new ImportJobsQuery, $id);
    }

    /** The staged rows of a job with their result, `status` filter optional, 100 per page. */
    public function rows(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:VALID,ERROR,SKIPPED,COMMITTED'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'import_jobs', $job);
        $status = $data['status'] ?? null;
        $page = (int) ($data['page'] ?? 1);
        $where = 'job_id = ?'.($status !== null ? ' and status = ?' : '');
        $bindings = $status !== null ? [$id, $status] : [$id];
        $total = (int) $db->selectOne("select count(*) as n from import_rows where {$where}", $bindings)->n;
        $rows = $db->select("select row_no, status, data, errors, entity_ref from import_rows where {$where} order by row_no limit 100 offset ?", [...$bindings, ($page - 1) * 100]);

        return response()->json([
            'data' => array_map(static fn (stdClass $r) => [
                'rowNo' => (int) $r->row_no, 'status' => $r->status, 'entityRef' => $r->entity_ref,
                'values' => json_decode((string) $r->data, true, flags: JSON_THROW_ON_ERROR),
                'errors' => json_decode((string) $r->errors, true, flags: JSON_THROW_ON_ERROR),
            ], $rows),
            'meta' => ['total' => $total, 'page' => ['number' => $page, 'size' => 100]],
        ]);
    }

    /** The error rows with their messages as a workbook. */
    public function errorFile(string $job): StreamedResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $row = $db->selectOne('select template, error_file_key from import_jobs where id = ?', [$this->existing($db, 'import_jobs', $job)]);
        if ($row->error_file_key === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found', 'This job has no error rows.');
        }

        return Storage::disk(ImportProcessor::DISK)->download((string) $row->error_file_key, sprintf('eora-%s-errors.xlsx', str_replace('_', '-', (string) $row->template)));
    }

    /** VALIDATED → SUBMITTED for commit: needs at least one valid row (and no error row for an all-or-nothing template). */
    public function submit(string $job): JsonResponse
    {
        [$db, $row] = $this->locked($job, self::SUBMIT, ['VALIDATED']);
        if ((int) $row->valid_rows === 0) {
            throw ValidationException::withMessages(['job' => 'No row is valid. Fix the file and upload it again.']);
        }
        // all-or-nothing templates (routings, BOMs, maps) commit only a file without a single error row (D-B2-07)
        if ((int) $row->error_rows > 0 && $this->templates->get((string) $row->template)->mode() === ImportTemplate::ALL_OR_NOTHING) {
            throw ValidationException::withMessages(['job' => 'This template imports everything or nothing: fix the rows with errors and upload the file again.']);
        }
        $this->move($db, $row, ['status' => 'SUBMITTED', 'submitted_by' => $this->actor($db), 'submitted_at' => now()->toIso8601String()], 'import_job.submitted');

        return $this->respond($db, new ImportJobsQuery, (string) $row->id);
    }

    /** SUBMITTED → COMMITTING by someone holding `exchange.import.post` and the template's master permission (D-081). */
    public function commit(string $job): JsonResponse
    {
        [$db, $row] = $this->locked($job, self::POST, ['SUBMITTED']);
        $this->authorizer->requireAnywhere($db, $this->templates->get((string) $row->template)->targetPermission());
        $this->move($db, $row, ['status' => 'COMMITTING', 'committed_by' => $this->actor($db), 'commit_started_at' => now()->toIso8601String()], 'import_job.commit_started');
        $this->dispatch($db, (string) $row->id, 'commit');

        return $this->respond($db, new ImportJobsQuery, (string) $row->id);
    }

    /** VALIDATED or SUBMITTED → REJECTED with a reason; the same file can be uploaded again later (a new job). */
    public function reject(Request $request, string $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $row] = $this->locked($job, self::POST, ['VALIDATED', 'SUBMITTED']);
        $this->move($db, $row, ['status' => 'REJECTED', 'reject_reason' => $data['reason']], 'import_job.rejected');

        return $this->respond($db, new ImportJobsQuery, (string) $row->id);
    }

    private function activeJob(EoraPostgresConnection $db, string $template, int $version, string $sha): ?stdClass
    {
        $in = implode(', ', array_fill(0, count(self::ACTIVE), '?'));
        $job = $db->selectOne("select id from import_jobs where template = ? and template_version = ? and file_sha256 = ? and status in ({$in})", [$template, $version, $sha, ...self::ACTIVE]);
        // an abandoned job no longer pins the file (B2-AT-51)
        if ($job !== null && ImportLease::reapIfLost($db, (string) $job->id)) {
            return null;
        }

        return $job;
    }

    /**
     * The job locked for a change from one of `$from`.
     *
     * @param  list<string>  $from
     * @return array{0: EoraPostgresConnection, 1: stdClass}
     */
    private function locked(string $job, string $permission, array $from): array
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'import_jobs', $job);
        $row = $db->selectOne('select * from import_jobs where id = ? for update', [$id]);
        if (! in_array($row->status, $from, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not possible in this state', sprintf('This job is %s.', strtolower((string) $row->status)));
        }

        return [$db, $row];
    }

    /** @param  array<string, mixed>  $changes  column => value (names are code) */
    private function move(EoraPostgresConnection $db, stdClass $row, array $changes, string $action): void
    {
        $id = (string) $row->id;
        $before = $this->state($db, 'import_jobs', $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($changes)));
        $db->update("update import_jobs set {$set} where id = ?", [...array_values($changes), $id]);
        $this->audit->record($db, $action, 'import_job', $id, $before, $this->state($db, 'import_jobs', $id), ['from' => $row->status, 'to' => $changes['status']]);
    }

    private function actor(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
    }

    /** Queues a worker step once this transaction commits; it acts as the user who uploaded or committed the job. */
    private function dispatch(EoraPostgresConnection $db, string $id, string $phase): void
    {
        $job = $db->selectOne('select tenant_id, created_by_user, committed_by from import_jobs where id = ?', [$id]);
        $actor = $phase === 'commit' ? (string) $job->committed_by : (string) $job->created_by_user;
        ProcessImport::dispatch((string) $job->tenant_id, $id, $phase, $actor)->afterCommit();
    }
}

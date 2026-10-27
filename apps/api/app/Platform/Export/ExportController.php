<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data exchange → Export (doc 08 §Secure export, doc 24 §3, D-092): a list is exported as a queued job that runs the
 * list's own QueryDefinition with the request's filters, search and sort, as the requester (`exchange.export.export`
 * plus the list's view permission, checked again when the job runs). The finished file is fetched through a signed
 * link that lasts 15 minutes and works only in the requester's session; the file is deleted after 24 hours.
 */
final class ExportController
{
    use MasterWrites;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly Exports $exports,
    ) {}

    /**
     * Queue an export of a list (`resource`, plus the list's `filter`, `q` and `sort` exactly as the list sends them).
     * Refused with 422 when the list has more rows than an export holds (at most 100,000, D-092): narrow the filter.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'resource' => ['required', 'string', 'max:64'],
            'filter' => ['nullable', 'array'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:200'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, Exports::PERMISSION);
        $definition = $this->exports->definition((string) $request->input('resource'));
        $input = Exports::input($request->input('filter'), $request->input('q'), $request->input('sort'));
        $query = $this->rows->build($db, $definition, $input);
        $count = (int) $db->selectOne($query->countSql(), $query->bindings)->n;
        if ($count > Exports::maxRows()) {
            throw ValidationException::withMessages(['query' => Exports::tooMany($count)]);
        }

        $id = (string) $db->selectOne('select uuidv7()::text as id')->id;
        $db->insert(
            'insert into export_jobs (tenant_id, id, resource, query, requested_by_user)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?::jsonb, (select c.actor from ctx_api.context() c))',
            [$id, $definition->resource(), json_encode(['title' => $definition->title(), ...$input], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
        $this->audit->record($db, 'export_job.requested', 'export_job', $id, null, $this->state($db, 'export_jobs', $id), ['resource' => $definition->resource(), 'rows' => $count]);
        $actor = (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
        ProcessExport::dispatch((string) $db->selectOne('select c.tenant from ctx_api.context() c')->tenant, $id, $actor)->afterCommit();

        return $this->respond($db, new ExportJobsQuery, $id, 201);
    }

    /** One of the caller's exports (another member's export is 404). */
    public function show(string $job): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, Exports::PERMISSION);

        return $this->respond($db, new ExportJobsQuery, $this->existing($db, 'export_jobs', $job));
    }

    /**
     * A download link for a finished export: relative, signed, valid 15 minutes, usable only in the caller's session.
     * Each link is audited. 409 while the export is not ready, or once its file was deleted (24 hours after writing).
     */
    public function link(string $job): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, Exports::PERMISSION);
        $id = $this->existing($db, 'export_jobs', $job);
        $this->stillAllowed($db, $id);
        $row = $db->selectOne('select status, expires_at <= now() as expired from export_jobs where id = ?', [$id]);
        if ($row->status !== 'DONE' || $row->expired) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not possible in this state', $row->status === 'DONE' || $row->status === 'EXPIRED'
                ? 'This export was deleted 24 hours after it was written. Export the list again.'
                : sprintf('This export is %s.', strtolower((string) $row->status)));
        }
        $until = now()->addMinutes(Exports::linkMinutes());
        $url = URL::temporarySignedRoute('v1.exchange.exports.file', $until, ['job' => $id], absolute: false);
        $this->audit->record($db, 'export_job.link_issued', 'export_job', $id);

        return response()->json(['data' => ['url' => $url, 'expiresAt' => $until->utc()->format('Y-m-d\TH:i:s\Z')]]);
    }

    /**
     * The file holds what the requester could see when it was written: a link or a download needs the list's view
     * permission now, with scopes that still cover the scopes the file was written with (403 otherwise).
     */
    private function stillAllowed(EoraPostgresConnection $db, string $id): void
    {
        $job = $db->selectOne('select resource, scopes from export_jobs where id = ?', [$id]);
        $current = $this->authorizer->requireAnywhere($db, $this->exports->definition((string) $job->resource)->permission());
        /** @var list<array{type: string, companyId: string|null, plantId: string|null}> $written */
        $written = $job->scopes === null ? [] : json_decode((string) $job->scopes, true, flags: JSON_THROW_ON_ERROR);
        if (! Exports::covered($written, $current)) {
            throw new DomainProblem(403, 'FORBIDDEN', 'Forbidden', 'Your roles no longer cover the data in this export.');
        }
    }

    /** The exported workbook, through a signed link from `link` (403 when the link is altered or older than 15 minutes). */
    public function file(string $job): StreamedResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, Exports::PERMISSION);
        $id = $this->existing($db, 'export_jobs', $job);
        $this->stillAllowed($db, $id);
        $row = $db->selectOne(
            "select file_key, file_name from export_jobs where id = ? and status = 'DONE' and expires_at > now()",
            [$id],
        ) ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found', 'This export file no longer exists.');

        return Storage::disk(Exports::DISK)->download((string) $row->file_key, (string) $row->file_name);
    }
}

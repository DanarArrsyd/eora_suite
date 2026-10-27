<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Query\BuiltQuery;
use App\Platform\Query\QueryDefinition;
use App\Platform\Query\QueryRunner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use DateTimeZone;
use Generator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The worker side of an export (D-092, doc 08 §Secure export): as the requester, in one tenant transaction, the list's
 * QueryDefinition runs again with the kept request — the view permission and its scopes as of now — the matching ids
 * are fixed in list order at one snapshot (at most 100,000), the rows are read by id in chunks and streamed into the
 * workbook, the file is stored for 24 hours and the requester gets an inbox notification. Nothing is half-written: a
 * failure marks the job FAILED and says so.
 */
final class ExportProcessor
{
    private const int CHUNK = 1000;

    public function __construct(
        private readonly TenantTransaction $tx,
        private readonly QueryRunner $rows,
        private readonly Exports $exports,
        private readonly AuditRecorder $audit,
    ) {}

    public function run(string $tenant, string $jobId, string $actor): void
    {
        $context = TenantContext::forTenant($tenant, $actor);
        $job = $this->tx->run($context, function (EoraPostgresConnection $db) use ($jobId) {
            $row = $db->selectOne('select * from export_jobs where id = ? for update', [$jobId]);
            if ($row === null || ! in_array($row->status, ['QUEUED', 'RUNNING'], true)) {
                return null; // finished already (a redelivered message)
            }
            $db->update("update export_jobs set status = 'RUNNING', started_at = now() where id = ?", [$jobId]);

            return $row;
        });
        if ($job === null) {
            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'eora-export-').'.xlsx';
        try {
            $this->tx->run($context, fn (EoraPostgresConnection $db) => $this->write($db, $job, $path));
        } catch (DomainProblem $problem) {
            // the requester no longer holds the export or the list's view permission
            $this->fail($tenant, $jobId, $actor, $problem->status === 403 ? 'NOT_ALLOWED' : 'EXPORT_FAILED', $problem->status === 403
                ? 'Your roles no longer allow this export.'
                : 'The export could not be written. Try again; if it fails again, tell your administrator.');
        } catch (TooManyRows $tooMany) {
            $this->fail($tenant, $jobId, $actor, 'TOO_MANY_ROWS', $tooMany->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($tenant, $jobId, $actor, 'EXPORT_FAILED', 'The export could not be written. Try again; if it fails again, tell your administrator.');
        } finally {
            @unlink($path);
        }
    }

    /** Marks a running or queued job FAILED and tells the requester (no-op for a job that already finished). */
    public function fail(string $tenant, string $jobId, string $actor, string $code, string $detail): void
    {
        $this->tx->run(TenantContext::forTenant($tenant, $actor), function (EoraPostgresConnection $db) use ($jobId, $code, $detail): void {
            $job = $db->selectOne('select * from export_jobs where id = ? for update', [$jobId]);
            if ($job === null || ! in_array($job->status, ['QUEUED', 'RUNNING'], true)) {
                return;
            }
            $db->update("update export_jobs set status = 'FAILED', failure_code = ?, failure_detail = ?, finished_at = now() where id = ?", [$code, $detail, $jobId]);
            $event = $this->audit->record($db, 'export_job.failed', 'export_job', $jobId, null, null, ['code' => $code]);
            $title = (string) (json_decode((string) $job->query, true)['title'] ?? 'List');
            $this->notify($db, $event, $job, 'exchange.export_failed', 'WARNING', 'Export failed', "{$title}: {$detail}");
        });
    }

    private function write(EoraPostgresConnection $db, \stdClass $job, string $path): void
    {
        /** @var array{title: string, filter: array<string, mixed>, q: string, sort: string|null} $request */
        $request = json_decode((string) $job->query, true, flags: JSON_THROW_ON_ERROR);
        $definition = $this->exports->definition((string) $job->resource);
        $query = $this->rows->build($db, $definition, $request);
        $ids = array_values(array_map(static fn (\stdClass $r) => (string) $r->id, $db->select($query->idsSql(), $query->bindings)));
        if (count($ids) > Exports::maxRows()) {
            throw new TooManyRows(Exports::tooMany(count($ids)));
        }

        $tenant = $db->selectOne('select t.id, t.timezone from tenants t where t.id = (select c.tenant from ctx_api.context() c)');
        $timezone = new DateTimeZone((string) ($tenant->timezone ?? 'Asia/Jakarta'));
        $now = Date::now()->setTimezone($timezone);
        $requester = (string) ($db->selectOne('select display_name from users where id = ?', [$job->requested_by_user])->display_name ?? '');
        $title = $definition->title();
        $count = ExportWorkbook::write($path, $title, $definition->exportColumns(), $this->rowsInOrder($db, $definition, $query, $ids), [
            ['List', $title],
            ['Rows', number_format(count($ids))],
            ['Search', $request['q'] !== '' ? $request['q'] : '—'],
            ['Filters', self::filters($request['filter'])],
            ['Sort', self::sort($request['sort'])],
            ['Data as of', $now->format('d/m/Y H:i:s').' '.$now->format('T').' ('.$timezone->getName().')'],
            ['Requested by', $requester],
            ['', ''],
            ['', sprintf('Generated by EORA Suite | Exported on %s %s', $now->format('d/m/Y H:i:s'), $now->format('T'))],
        ], $timezone);

        $name = sprintf('%s-%s.xlsx', Str::slug($title) ?: 'export', $now->format('Ymd-Hi'));
        $key = sprintf('tenant/%s/exports/%s/%s', (string) $tenant->id, (string) $job->id, $name);
        $stream = fopen($path, 'rb') ?: throw new \RuntimeException('The export file could not be read.');
        Storage::disk(Exports::DISK)->writeStream($key, $stream);
        fclose($stream);

        $db->update(
            "update export_jobs set status = 'DONE', row_count = ?, file_key = ?, file_name = ?, file_size = ?, finished_at = now(),
                    expires_at = now() + make_interval(hours => ?) where id = ?",
            [$count, $key, $name, (int) filesize($path), Exports::fileHours(), $job->id],
        );
        $event = $this->audit->record($db, 'export_job.completed', 'export_job', (string) $job->id, null, null, ['resource' => $job->resource, 'rows' => $count]);
        $this->notify($db, $event, $job, 'exchange.export_ready', 'INFO', 'Export ready', sprintf(
            '%s: %s rows. Download it from Exports within %d hours.', $title, number_format($count), Exports::fileHours(),
        ));
    }

    /**
     * The rows of $ids in list order, read in chunks by id with the full FROM.
     *
     * @param  list<string>  $ids
     * @return Generator<array<string, mixed>>
     */
    private function rowsInOrder(EoraPostgresConnection $db, QueryDefinition $definition, BuiltQuery $query, array $ids): Generator
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $byId = [];
            foreach ($db->select($query->rowsByIdSql(), ['{'.implode(',', $chunk).'}']) as $row) {
                $id = (string) $row->row_id;
                unset($row->row_id);
                $byId[$id] = $definition->row($row);
            }
            foreach ($chunk as $id) {
                if (isset($byId[$id])) {
                    yield $byId[$id];
                }
            }
        }
    }

    /** `-code,name` as "code (descending), name" — the list's default order when none was chosen. */
    private static function sort(?string $sort): string
    {
        if ($sort === null) {
            return 'List order';
        }

        return implode(', ', array_map(static fn (string $p) => str_starts_with($p, '-') ? substr($p, 1).' (descending)' : $p, explode(',', $sort)));
    }

    /** @param  array<string, mixed>  $filter */
    private static function filters(array $filter): string
    {
        $parts = [];
        foreach ($filter as $key => $value) {
            $values = array_filter(is_array($value) ? $value : [$value], static fn ($v) => is_string($v) && $v !== '');
            if ($values !== []) {
                $parts[] = $key.': '.implode(', ', $values);
            }
        }

        return $parts === [] ? 'None' : implode('; ', $parts);
    }

    private function notify(EoraPostgresConnection $db, string $event, \stdClass $job, string $kind, string $severity, string $title, string $body): void
    {
        $db->insert(
            "insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, title, body, deep_link)
             select m.tenant_id, m.id, m.user_id, ?::uuid, ?, ?, ?, ?, ? from memberships m where m.user_id = ? and m.status = 'ACTIVE'",
            [$event, $kind, $severity, $title, mb_substr($body, 0, 1000), '/system/exports?job='.$job->id, $job->requested_by_user],
        );
    }
}

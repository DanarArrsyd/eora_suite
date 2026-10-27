<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Access\Authorizer;
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
 * The worker side of an export (D-092, doc 08 §Secure export, ADR-0018): as the requester, in one tenant transaction,
 * the export permission and the list's QueryDefinition are checked again — the view permission and its scopes as of
 * now — the matching ids are fixed in list order at one snapshot (at most 100,000), the rows are read by id in chunks
 * and streamed into the workbook, the file is stored for 24 hours with the scopes it was written with, and the
 * requester gets an inbox notification. Only short transactions: the claim, the plan (permissions, ids), one read per
 * chunk while the workbook streams, then the finish — the upload runs outside any transaction, so neither the signed
 * context's lifetime nor the idle-transaction timeout bounds an export. An attempt claims the job with a lease and a
 * generation and finishes it only with that generation; it writes into `g<generation>/` of the job's folder, a lost
 * claim deletes its own file and a finish keeps the winner's file only, a failure empties the folder — no file outlives
 * its job. Nothing is half-written: a failure marks the job FAILED and says so.
 */
final class ExportProcessor
{
    private const int CHUNK = 1000;

    /** Longer than the longest attempt (ProcessExport::$timeout): a lease still running means an attempt is alive. */
    private const int LEASE_SECONDS = 1200;

    public function __construct(
        private readonly TenantTransaction $tx,
        private readonly QueryRunner $rows,
        private readonly Exports $exports,
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * One attempt at a job.
     *
     * @return int|null seconds to wait while another attempt holds a live lease; null when nothing (more) is to be done
     */
    public function run(string $tenant, string $jobId, string $actor): ?int
    {
        $context = TenantContext::forTenant($tenant, $actor);
        $claim = $this->tx->run($context, function (EoraPostgresConnection $db) use ($jobId): ?array {
            $row = $db->selectOne('select status, extract(epoch from lease_until - now()) as lease_left from export_jobs where id = ? for update', [$jobId]);
            if ($row === null || ! in_array($row->status, ['QUEUED', 'RUNNING'], true)) {
                return null; // finished already (a redelivered message)
            }
            if ($row->status === 'RUNNING' && $row->lease_left !== null && (float) $row->lease_left > 0) {
                return ['wait' => (int) ceil((float) $row->lease_left)];
            }

            return ['generation' => (int) $db->selectOne(
                "update export_jobs set status = 'RUNNING', started_at = now(), claim_generation = claim_generation + 1,
                        lease_until = now() + make_interval(secs => ?) where id = ? returning claim_generation",
                [self::LEASE_SECONDS, $jobId],
            )->claim_generation];
        });
        if ($claim === null) {
            return null;
        }
        if (isset($claim['wait'])) {
            return $claim['wait'];
        }

        $generation = (int) $claim['generation'];
        $path = tempnam(sys_get_temp_dir(), 'eora-export-').'.xlsx';
        $key = null;
        try {
            // short transactions only (the signed context lasts minutes, idle transactions seconds): plan, then one
            // read per chunk while the workbook streams, the upload outside any transaction, then the fenced finish
            $plan = $this->tx->run($context, fn (EoraPostgresConnection $db) => $this->plan($db, $jobId, $generation));
            $count = ExportWorkbook::write($path, $plan['title'], $plan['definition']->exportColumns(), $this->rowsInOrder($context, $plan['definition'], $plan['query'], $plan['ids']), $plan['about'], $plan['timezone']);
            $key = sprintf('%s/g%d/%s', Exports::folder($tenant, $jobId), $generation, $plan['name']);
            $stream = fopen($path, 'rb') ?: throw new \RuntimeException('The export file could not be read.');
            Storage::disk(Exports::DISK)->writeStream($key, $stream);
            fclose($stream);
            $this->tx->run($context, fn (EoraPostgresConnection $db) => $this->finish($db, $jobId, $generation, $plan, $key, $count, (int) filesize($path)));
            // the job's folder keeps this attempt's file only (an earlier attempt may have died after its upload)
            $disk = Storage::disk(Exports::DISK);
            foreach ($disk->allFiles(Exports::folder($tenant, $jobId)) as $file) {
                if ($file !== $key) {
                    $disk->delete($file);
                }
            }
        } catch (LostClaim) {
            // a newer attempt took the job over; it writes and finishes it — this attempt's file goes
            Storage::disk(Exports::DISK)->deleteDirectory(sprintf('%s/g%d', Exports::folder($tenant, $jobId), $generation));
        } catch (DomainProblem $problem) {
            // the requester no longer holds the export or the list's view permission
            $this->fail($tenant, $jobId, $actor, $problem->status === 403 ? 'NOT_ALLOWED' : 'EXPORT_FAILED', $problem->status === 403
                ? 'Your roles no longer allow this export.'
                : 'The export could not be written. Try again; if it fails again, tell your administrator.', $generation);
        } catch (TooManyRows $tooMany) {
            $this->fail($tenant, $jobId, $actor, 'TOO_MANY_ROWS', $tooMany->getMessage(), $generation);
        } catch (Throwable $e) {
            report($e);
            $this->fail($tenant, $jobId, $actor, 'EXPORT_FAILED', 'The export could not be written. Try again; if it fails again, tell your administrator.', $generation);
        } finally {
            @unlink($path);
        }

        return null;
    }

    /**
     * Marks a running or queued job FAILED, empties its folder and tells the requester — only for the attempt that holds
     * the claim (`$generation`; null: whoever, e.g. the queue giving up); a finished job stays as it is.
     */
    public function fail(string $tenant, string $jobId, string $actor, string $code, string $detail, ?int $generation = null): void
    {
        $this->tx->run(TenantContext::forTenant($tenant, $actor), function (EoraPostgresConnection $db) use ($tenant, $jobId, $code, $detail, $generation): void {
            $job = $db->selectOne('select * from export_jobs where id = ? for update', [$jobId]);
            if ($job === null || ! in_array($job->status, ['QUEUED', 'RUNNING'], true)
                || ($generation !== null && (int) $job->claim_generation !== $generation)) {
                if ($generation !== null) {
                    Storage::disk(Exports::DISK)->deleteDirectory(sprintf('%s/g%d', Exports::folder($tenant, $jobId), $generation));
                }

                return;
            }
            $db->update("update export_jobs set status = 'FAILED', failure_code = ?, failure_detail = ?, finished_at = now(), lease_until = null where id = ?", [$code, $detail, $jobId]);
            Storage::disk(Exports::DISK)->deleteDirectory(Exports::folder($tenant, $jobId));
            $event = $this->audit->record($db, 'export_job.failed', 'export_job', $jobId, null, null, ['code' => $code]);
            $title = (string) (json_decode((string) $job->query, true)['title'] ?? 'List');
            $this->notify($db, $event, $job, 'exchange.export_failed', 'WARNING', 'Export failed', "{$title}: {$detail}");
        });
    }

    /**
     * The claimed job's export: permissions checked again, the matching ids fixed in list order at one snapshot.
     *
     * @return array{job: \stdClass, definition: QueryDefinition, query: BuiltQuery, ids: list<string>, title: string, name: string, timezone: DateTimeZone, about: list<array{0: string, 1: string}>}
     */
    private function plan(EoraPostgresConnection $db, string $jobId, int $generation): array
    {
        $job = $db->selectOne("select * from export_jobs where id = ? and claim_generation = ? and status = 'RUNNING'", [$jobId, $generation])
            ?? throw new LostClaim;
        $this->authorizer->requireAnywhere($db, Exports::PERMISSION);
        /** @var array{title: string, filter: array<string, mixed>, q: string, sort: string|null} $request */
        $request = json_decode((string) $job->query, true, flags: JSON_THROW_ON_ERROR);
        $definition = $this->exports->definition((string) $job->resource);
        $query = $this->rows->build($db, $definition, $request);
        $ids = array_values(array_map(static fn (\stdClass $r) => (string) $r->id, $db->select($query->idsSql(), $query->bindings)));
        if (count($ids) > Exports::maxRows()) {
            throw new TooManyRows(Exports::tooMany(count($ids)));
        }

        $tenant = $db->selectOne('select t.timezone from tenants t where t.id = (select c.tenant from ctx_api.context() c)');
        $timezone = new DateTimeZone((string) ($tenant->timezone ?? 'Asia/Jakarta'));
        $now = Date::now()->setTimezone($timezone);
        $requester = (string) ($db->selectOne('select display_name from users where id = ?', [$job->requested_by_user])->display_name ?? '');
        $title = $definition->title();

        return [
            'job' => $job,
            'definition' => $definition,
            'query' => $query,
            'ids' => $ids,
            'title' => $title,
            'name' => sprintf('%s-%s.xlsx', Str::slug($title) ?: 'export', $now->format('Ymd-Hi')),
            'timezone' => $timezone,
            'about' => [
                ['List', $title],
                ['Rows', number_format(count($ids))],
                ['Search', $request['q'] !== '' ? $request['q'] : '—'],
                ['Filters', self::filters($request['filter'])],
                ['Sort', self::sort($request['sort'])],
                ['Data as of', $now->format('d/m/Y H:i:s').' '.$now->format('T').' ('.$timezone->getName().')'],
                ['Requested by', $requester],
                ['', ''],
                ['', sprintf('Generated by EORA Suite | Exported on %s %s', $now->format('d/m/Y H:i:s'), $now->format('T'))],
            ],
        ];
    }

    /**
     * Finishes the job — only while this attempt still holds the claim (fencing: a newer generation wins).
     *
     * @param  array{job: \stdClass, query: BuiltQuery, title: string, name: string}  $plan
     */
    private function finish(EoraPostgresConnection $db, string $jobId, int $generation, array $plan, string $key, int $count, int $size): void
    {
        $db->selectOne("select id from export_jobs where id = ? and claim_generation = ? and status = 'RUNNING' for update", [$jobId, $generation])
            ?? throw new LostClaim;
        $db->update(
            "update export_jobs set status = 'DONE', row_count = ?, scopes = ?::jsonb, file_key = ?, file_name = ?, file_size = ?, finished_at = now(),
                    lease_until = null, expires_at = now() + make_interval(hours => ?) where id = ?",
            [$count, json_encode(Exports::scopes($plan['query']->scopes), JSON_THROW_ON_ERROR), $key, $plan['name'], $size, Exports::fileHours(), $jobId],
        );
        $event = $this->audit->record($db, 'export_job.completed', 'export_job', $jobId, null, null, ['resource' => $plan['job']->resource, 'rows' => $count]);
        $this->notify($db, $event, $plan['job'], 'exchange.export_ready', 'INFO', 'Export ready', sprintf(
            '%s: %s rows. Download it from Exports within %d hours.', $plan['title'], number_format($count), Exports::fileHours(),
        ));
    }

    /**
     * The rows of $ids in list order, read by id with the full FROM — one short transaction per chunk.
     *
     * @param  list<string>  $ids
     * @return Generator<array<string, mixed>>
     */
    private function rowsInOrder(TenantContext $context, QueryDefinition $definition, BuiltQuery $query, array $ids): Generator
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->tx->run($context, fn (EoraPostgresConnection $db) => $db->select($query->rowsByIdSql(), ['{'.implode(',', $chunk).'}']));
            $byId = [];
            foreach ($rows as $row) {
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

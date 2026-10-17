<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Database\EoraPostgresConnection;

/**
 * A worker's claim on one import job (doc 26 §5, ADR-0017 §5): claimed only when free or expired, kept alive by
 * heartbeats of the same generation, and checked with `FOR SHARE` at the start of every write transaction, so a
 * takeover waits for an in-flight chunk and a paused worker can never write after losing ownership.
 */
final readonly class ImportLease
{
    private function __construct(public string $jobId, public int $generation, public string $worker) {}

    public static function seconds(): int
    {
        return max(5, (int) config('eora.import.lease_seconds', 300));
    }

    /**
     * Claims the job in one of `$from` and moves it to `$to`; null when another live worker holds it or the status no
     * longer fits.
     *
     * @param  list<string>  $from
     */
    public static function claim(EoraPostgresConnection $db, string $jobId, array $from, string $to, string $worker): ?self
    {
        $in = implode(', ', array_fill(0, count($from), '?'));
        $row = $db->selectOne(
            "update import_jobs
                set claim_generation = claim_generation + 1, locked_by = ?, lease_until = now() + make_interval(secs => ?), status = ?
              where id = ? and status in ({$in}) and (locked_by is null or lease_until < now())
             returning claim_generation",
            [$worker, self::seconds(), $to, $jobId, ...$from],
        );

        return $row === null ? null : new self($jobId, (int) $row->claim_generation, $worker);
    }

    /** Throws LostLease unless this generation still owns the job, its lease is live and it is in `$status`. */
    public function fence(EoraPostgresConnection $db, string $status): void
    {
        $job = $db->selectOne('select claim_generation, lease_until > now() as live, status from import_jobs where id = ? for share', [$this->jobId]);
        if ($job === null || (int) $job->claim_generation !== $this->generation || ! $job->live || $job->status !== $status) {
            throw new LostLease("Import job {$this->jobId} is no longer owned by generation {$this->generation}.");
        }
    }

    /** Extends the lease (same generation only). */
    public function heartbeat(EoraPostgresConnection $db): void
    {
        $db->update(
            'update import_jobs set lease_until = now() + make_interval(secs => ?) where id = ? and claim_generation = ?',
            [self::seconds(), $this->jobId, $this->generation],
        );
    }

    /**
     * An abandoned job — its lease expired while VALIDATING or COMMITTING — becomes FAILED (WORKER_LOST); the generation
     * moves on so the lost worker can never write. Returns whether it did.
     */
    public static function reapIfLost(EoraPostgresConnection $db, string $jobId): bool
    {
        return $db->update(
            "update import_jobs
                set status = 'FAILED', failure_code = 'WORKER_LOST', failure_detail = 'The worker stopped before finishing. Upload the same file again to retry.',
                    claim_generation = claim_generation + 1, locked_by = null, lease_until = null
              where id = ? and status in ('VALIDATING', 'COMMITTING') and lease_until < now()",
            [$jobId],
        ) > 0;
    }
}

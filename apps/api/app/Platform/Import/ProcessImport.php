<?php

declare(strict_types=1);

namespace App\Platform\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queue message for the import worker (`import-worker`, queue `imports`, ADR-0017 §4): only ids from the job row, never
 * client input. A redelivered message after a worker crash waits until the lease expired, then takes the job over.
 */
final class ProcessImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    /** Retries (crashes, leases still held by a lost worker) are bounded by time rather than by count. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly string $phase,
        public readonly string $actorId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(ImportProcessor $processor): void
    {
        $worker = sprintf('%s:%d:%s', gethostname() ?: 'worker', getmypid() ?: 0, bin2hex(random_bytes(4)));
        try {
            $this->phase === 'commit'
                ? $processor->commit($this->tenantId, $this->jobId, $this->actorId, $worker)
                : $processor->validate($this->tenantId, $this->jobId, $this->actorId, $worker);
        } catch (LeaseBusy $busy) {
            // another worker (or a crashed one) still holds the lease: come back once it expired and take over
            $this->release($busy->seconds + 2);
        }
    }
}

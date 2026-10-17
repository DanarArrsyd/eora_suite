<?php

declare(strict_types=1);

namespace App\Platform\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queue message for the import worker (`import-worker`, queue `imports`, ADR-0017 §4): only ids from the job row, never
 * client input. A redelivered message after a worker crash takes the job over once its lease expired.
 */
final class ProcessImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

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
        $this->phase === 'commit'
            ? $processor->commit($this->tenantId, $this->jobId, $this->actorId, $worker)
            : $processor->validate($this->tenantId, $this->jobId, $this->actorId, $worker);
    }
}

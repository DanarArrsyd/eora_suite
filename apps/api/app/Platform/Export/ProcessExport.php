<?php

declare(strict_types=1);

namespace App\Platform\Export;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queue message for an export (queue `exports`, worker `import-worker`, D-092): only ids, never client input — the
 * worker reads the list request from the job row. A message redelivered while an attempt is alive waits for its
 * lease; after a crash it takes the job over and writes the file again.
 */
final class ProcessExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly string $actorId,
    ) {
        $this->onQueue('exports');
    }

    public function handle(ExportProcessor $processor): void
    {
        $wait = $processor->run($this->tenantId, $this->jobId, $this->actorId);
        if ($wait !== null) {
            // another attempt still holds the job: come back once its lease ran out (it will have finished, or died)
            $this->release($wait + 2);
        }
    }

    /** Out of retries (e.g. the worker kept dying): the job is told as failed instead of staying queued. */
    public function failed(?\Throwable $e): void
    {
        app(ExportProcessor::class)->fail($this->tenantId, $this->jobId, $this->actorId, 'EXPORT_FAILED', 'The export could not be written. Try again; if it fails again, tell your administrator.');
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Retries a failed security-event flush (doc 25 §8). Payload holds identifiers only, never secrets. */
final class RetrySecurityEventFlush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public readonly ?string $tenantId, public readonly string $actorId, public readonly array $events) {}

    public function handle(TenantTransaction $transaction): void
    {
        SecurityEventRecorder::write(
            $transaction,
            new TenantContext($this->tenantId, $this->actorId),
            array_map(static fn (array $e) => SecurityEvent::fromArray($e), $this->events),
        );
    }
}

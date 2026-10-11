<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Request-scoped buffer for security events (doc 25 §8, D-B1-20). Events are buffered in memory while the request or
 * job transaction runs (renderer, policies: no database call), then flushed AFTER that transaction committed or rolled
 * back, in a separate short tenant transaction — so a denial is recorded even when the business work rolls back, and
 * the business transaction is never reopened. Every event is logged first; a failed flush is queued for retry.
 */
final class SecurityEventRecorder
{
    /** @var list<SecurityEvent> */
    private array $buffer = [];

    public function __construct(private readonly TenantTransaction $transaction) {}

    public function record(SecurityEvent $event): void
    {
        $this->buffer[] = $event;
    }

    /** @return list<SecurityEvent> */
    public function pending(): array
    {
        return $this->buffer;
    }

    /**
     * Writes the buffered events for the given context and clears the buffer. Never throws: on failure the events are
     * logged and handed to a retry job; the caller's response is unchanged.
     */
    public function flush(?TenantContext $context): void
    {
        $events = $this->buffer;
        $this->buffer = [];

        if ($events === []) {
            return;
        }

        foreach ($events as $event) {
            Log::info('security_event', $event->toLog() + ['tenant_id' => $context?->tenantId]);
        }

        if ($context === null) {
            return; // unauthenticated: no actor context, the JSON log line is the record (doc 25 §8 residual)
        }

        try {
            self::write($this->transaction, $context, $events);
        } catch (Throwable $e) {
            Log::warning('security_event_flush_failed', ['error' => class_basename($e), 'count' => count($events)]);
            RetrySecurityEventFlush::dispatch($context->tenantId, $context->actorId, array_map(static fn (SecurityEvent $e) => $e->toArray(), $events));
        }
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    public static function write(TenantTransaction $transaction, TenantContext $context, array $events): void
    {
        $transaction->run($context, function (EoraPostgresConnection $db) use ($context, $events): void {
            foreach ($events as $event) {
                $db->insert(
                    'insert into security_events (tenant_id, user_id, source, action, resource, requested_id, route, http_status, correlation_id, ip_hash, user_agent_hash, detail_code)
                     values (?, ?, \'app\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$context->tenantId, $context->actorId, $event->action, $event->resource, $event->requestedId, $event->route,
                        $event->httpStatus, $event->correlationId, $event->ipHash, $event->userAgentHash, $event->detailCode],
                );
            }
        });
    }
}

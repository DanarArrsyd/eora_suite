<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Database\EoraPostgresConnection;
use LogicException;

/**
 * Transactional outbox (doc 25 §6.4, doc 04): the event row is written in the SAME transaction as the business change,
 * so it exists exactly when the change committed. Always `source = 'app'`; `auth.*` types are reserved for auth sources
 * by a CHECK. Payloads carry ids and codes only.
 */
final class OutboxPublisher
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public function publish(EoraPostgresConnection $db, string $eventType, string $aggregateType, string $aggregateId, array $payload = []): string
    {
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Outbox events are written inside the command transaction.');
        }

        return (string) $db->selectOne(
            "insert into outbox_events (tenant_id, source, event_type, aggregate_type, aggregate_id, payload)
             values ((select c.tenant from ctx_api.context() c), 'app', ?, ?, ?, ?::jsonb) returning id",
            [$eventType, $aggregateType, $aggregateId, json_encode($payload === [] ? new \stdClass : $payload, JSON_THROW_ON_ERROR)],
        )->id;
    }
}

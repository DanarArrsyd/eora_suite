<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityHasher;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Business audit (doc 25 §8, doc 05): one `audit_events` row per command, written in the SAME transaction as the
 * change — it rolls back with it. The database stamps actor and time from the signed context. `detail` carries
 * identifiers and codes only (no e-mail, no secrets); before/after are stored as SHA-256 of their canonical JSON.
 */
final class AuditRecorder
{
    public function __construct(private readonly SecurityHasher $hasher) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $detail
     */
    public function record(
        EoraPostgresConnection $db,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        array $detail = [],
        ?string $plantId = null,
    ): string {
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Audit events are written inside the command transaction.');
        }
        $correlation = (string) (Context::get('correlation_id') ?? '');
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return (string) $db->selectOne(
            'insert into audit_events (tenant_id, action, entity_type, entity_id, plant_id, before_hash, after_hash, detail, correlation_id, ip_hash, user_agent_hash)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?) returning id',
            [
                $action, $entityType, $entityId, $plantId, self::digest($before), self::digest($after),
                json_encode($detail === [] ? new \stdClass : $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                preg_match('/^[A-Za-z0-9_-]{8,64}$/', $correlation) === 1 ? $correlation : null,
                $request === null ? null : $this->hasher->hash($request->ip()),
                $request === null ? null : $this->hasher->hash((string) $request->userAgent()),
            ],
        )->id;
    }

    /** @param  array<string, mixed>|null  $state */
    public static function digest(?array $state): ?string
    {
        return $state === null ? null : hash('sha256', json_encode(self::canonical($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}

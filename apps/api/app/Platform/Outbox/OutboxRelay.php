<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Outbox relay (doc 25 §6.4): claims events as eora_relay (ids and tenant ids only), then for each event opens a
 * tenant transaction on eora_app with the claimed tenant, re-reads the event under RLS, and runs every consumer of
 * its type once (processed_events). After the commit the claim is completed; any failure releases it with backoff.
 */
final class OutboxRelay
{
    /** @var array<string, list<class-string<OutboxConsumer>>> event type => consumers */
    public const array CONSUMERS = [
        'auth.password_reset_consumed' => [PrivilegedResetNotifier::class],
    ];

    public function __construct(private readonly TenantTransaction $transaction) {}

    /** Processes one batch; returns the number of events claimed. */
    public function runOnce(string $worker, int $batch = 50): int
    {
        $relay = DB::connection('pgsql_relay');
        $claims = $relay->select('select * from outbox_api.claim_events(?, ?)', [$worker, $batch]);

        foreach ($claims as $claim) {
            try {
                $this->transaction->run(TenantContext::system((string) $claim->tenant_id), fn (EoraPostgresConnection $db) => $this->handle($db, (string) $claim->event_id));
                $relay->select('select outbox_api.complete_event(?, ?)', [$claim->event_id, $claim->claim_token]);
            } catch (Throwable $e) {
                Log::warning('outbox_event_failed', ['event_id' => $claim->event_id, 'tenant_id' => $claim->tenant_id, 'error' => class_basename($e)]);
                try {
                    $relay->select('select outbox_api.fail_event(?, ?, ?)', [$claim->event_id, $claim->claim_token, self::errorCode($e)]);
                } catch (Throwable $release) {
                    // lease already expired or reclaimed: the next claim picks it up (doc 25 §6.4 crash recovery)
                    Log::warning('outbox_event_release_rejected', ['event_id' => $claim->event_id, 'error' => class_basename($release)]);
                }
            }
        }

        return count($claims);
    }

    /** Runs every consumer of the event once (processed_events); call inside the event's tenant transaction. */
    public function handle(EoraPostgresConnection $db, string $eventId): void
    {
        // RLS: a claim whose tenant does not match the stored row finds nothing
        $event = $db->selectOne('select * from outbox_events where id = ?', [$eventId])
            ?? throw new RuntimeException('Outbox event not visible in the claimed tenant.');

        foreach (self::CONSUMERS[(string) $event->event_type] ?? [] as $class) {
            $consumer = app($class);
            $first = $db->affectingStatement(
                'insert into processed_events (tenant_id, consumer, event_id) values (?, ?, ?) on conflict do nothing',
                [$event->tenant_id, $consumer->name(), $event->id],
            ) === 1;
            if ($first) {
                $consumer->handle($db, $event);
            }
        }
    }

    private static function errorCode(Throwable $e): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', class_basename($e)));

        return substr(preg_match('/^[A-Z]/', $code) === 1 ? $code : 'E_'.$code, 0, 64);
    }
}

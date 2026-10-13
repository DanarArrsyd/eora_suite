<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Database\EoraPostgresConnection;
use stdClass;

/** A handler of outbox events, run at most once per event (processed_events) inside the event's tenant transaction. */
interface OutboxConsumer
{
    /** Stable consumer name, stored in processed_events (`^[a-z][a-z_]*$`). */
    public function name(): string;

    /** Runs inside the tenant transaction; durable follow-up work is written as rows in the same transaction (R-40). */
    public function handle(EoraPostgresConnection $db, stdClass $event): void;
}

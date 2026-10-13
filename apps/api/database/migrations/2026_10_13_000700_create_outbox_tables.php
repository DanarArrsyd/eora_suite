<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 7b (doc 25 §6.4, §8, §9 row 13; R-30, R-31/R-35, R-33, R-40): transactional outbox + processed events.
 * - outbox_events is tenant-owned; the runtime role inserts only `source = 'app'` rows of its tenant, and `auth.*`
 *   event types are reserved for auth sources (one-way CHECK). Auth definer functions insert only
 *   `auth.password_reset_consumed` from `auth_web`.
 * - The relay claims globally through `outbox_api` (bootstrap 40_outbox.sql, owner NOLOGIN eora_outbox_definer): that
 *   definer reads only ids, tenant ids and claim columns — never payloads or event types — and writes claim columns.
 * - processed_events makes each consumer handle an event at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE outbox_events (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              source text NOT NULL CHECK (source IN ('app', 'auth_web', 'auth_delivery')),
              event_type text NOT NULL CHECK (event_type ~ '^[a-z][a-z_]*(\.[a-z_]+)+$' AND length(event_type) <= 64),
              aggregate_type text CHECK (aggregate_type ~ '^[a-z][a-z_]{0,63}$'),
              aggregate_id uuid,
              payload jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(payload) = 'object' AND pg_column_size(payload) <= 8192),
              occurred_at timestamptz NOT NULL DEFAULT now(),
              status text NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'CLAIMED', 'DONE', 'DEAD')),
              claim_token uuid,
              claimed_until timestamptz,
              attempts integer NOT NULL DEFAULT 0,
              max_attempts integer NOT NULL DEFAULT 10 CHECK (max_attempts BETWEEN 1 AND 50),
              next_attempt_at timestamptz NOT NULL DEFAULT now(),
              last_error_code text CHECK (last_error_code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
              dispatched_at timestamptz,
              dead_at timestamptz,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              -- reserved namespace, one-way (doc 25 §8, R-31/R-35): auth.* types only from auth sources
              CHECK (event_type NOT LIKE 'auth.%' OR source IN ('auth_web', 'auth_delivery'))
            );
            CREATE INDEX outbox_events_due_idx ON outbox_events (next_attempt_at, id) WHERE status IN ('PENDING', 'CLAIMED');

            ALTER TABLE outbox_events ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY app_insert ON outbox_events FOR INSERT TO eora_app
              WITH CHECK (source = 'app' AND tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY tenant_read ON outbox_events FOR SELECT TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT (tenant_id, id, source, event_type, aggregate_type, aggregate_id, payload) ON outbox_events TO eora_app;

            -- auth definer (consume_password_reset): one fixed type from one fixed source, INSERT only (doc 25 §2.3, R-26)
            CREATE POLICY definer_insert ON outbox_events FOR INSERT TO eora_auth_definer
              WITH CHECK (source = 'auth_web' AND event_type = 'auth.password_reset_consumed');
            GRANT INSERT (tenant_id, id, source, event_type, aggregate_type, aggregate_id, payload) ON outbox_events TO eora_auth_definer;

            -- outbox definer (claim/complete/fail): global by design, confined by column grants (R-33)
            CREATE POLICY relay_claim_read ON outbox_events FOR SELECT TO eora_outbox_definer USING (true);
            CREATE POLICY relay_claim_update ON outbox_events FOR UPDATE TO eora_outbox_definer USING (true) WITH CHECK (true);
            GRANT SELECT (id, tenant_id, status, claim_token, claimed_until, attempts, max_attempts, next_attempt_at)
              ON outbox_events TO eora_outbox_definer;
            GRANT UPDATE (status, claim_token, claimed_until, attempts, next_attempt_at, last_error_code, dispatched_at, dead_at)
              ON outbox_events TO eora_outbox_definer;

            CREATE TABLE processed_events (
              tenant_id uuid NOT NULL,
              consumer text NOT NULL CHECK (consumer ~ '^[a-z][a-z_]{0,63}$'),
              event_id uuid NOT NULL,
              processed_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (consumer, event_id),
              FOREIGN KEY (tenant_id, event_id) REFERENCES outbox_events (tenant_id, id)
            );
            ALTER TABLE processed_events ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON processed_events TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON processed_events TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE processed_events;
            DROP TABLE outbox_events;
            SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 3 (doc 25 §8, D-B1-20): durable, append-only security events (denied access, auth outcomes).
 * The runtime role inserts only source = 'app' rows for its own signed context and can read only its tenant's rows;
 * auth sources are reserved for the auth definer functions (added with identity, doc 25 §2.8). No UPDATE/DELETE for
 * anyone through the API roles; a trigger also blocks UPDATE, DELETE and TRUNCATE for the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE security_events (
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              occurred_at timestamptz NOT NULL DEFAULT now(),
              tenant_id uuid REFERENCES tenants (id),
              user_id uuid,
              membership_id uuid,
              source text NOT NULL CHECK (source IN ('app', 'auth_web', 'auth_delivery')),
              action text NOT NULL CHECK (action ~ '^[a-z][a-z_]*(\.[a-z_]+)+$' AND length(action) <= 64),
              resource text CHECK (length(resource) <= 64),
              requested_id text CHECK (requested_id ~ '^[A-Za-z0-9_-]{1,64}$'),
              route text CHECK (length(route) <= 200),
              http_status smallint CHECK (http_status BETWEEN 100 AND 599),
              correlation_id text CHECK (correlation_id ~ '^[A-Za-z0-9_-]{8,64}$'),
              ip_hash text CHECK (ip_hash ~ '^[0-9a-f]{64}$'),
              user_agent_hash text CHECK (user_agent_hash ~ '^[0-9a-f]{64}$'),
              email_hmac text CHECK (email_hmac ~ '^[0-9a-f]{64}$'),
              detail_code text CHECK (detail_code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
              -- reserved namespace (doc 25 §8, R-31/R-35): auth.* actions only from auth sources
              CHECK (action NOT LIKE 'auth.%' OR source IN ('auth_web', 'auth_delivery'))
            );
            CREATE INDEX security_events_tenant_time_idx ON security_events (tenant_id, occurred_at DESC);

            CREATE OR REPLACE FUNCTION eora_append_only() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = '42501';
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_append_only() FROM PUBLIC;
            CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON security_events FOR EACH ROW EXECUTE FUNCTION eora_append_only();
            CREATE TRIGGER append_only_truncate BEFORE TRUNCATE ON security_events FOR EACH STATEMENT EXECUTE FUNCTION eora_append_only();

            ALTER TABLE security_events ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            -- runtime writes: source 'app' only, own tenant (or tenant-less with an authenticated actor), and the event's
            -- user, when given, is the signed actor itself
            CREATE POLICY app_insert ON security_events FOR INSERT TO eora_app
              WITH CHECK (
                source = 'app'
                AND (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)
                     OR (tenant_id IS NULL AND (SELECT c.tenant FROM ctx_api.context() c) IS NULL
                         AND (SELECT c.actor FROM ctx_api.context() c) IS NOT NULL))
                AND (user_id IS NULL OR user_id = (SELECT c.actor FROM ctx_api.context() c))
              );
            CREATE POLICY tenant_read ON security_events FOR SELECT TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON security_events TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE security_events; DROP FUNCTION eora_append_only();');
    }
};

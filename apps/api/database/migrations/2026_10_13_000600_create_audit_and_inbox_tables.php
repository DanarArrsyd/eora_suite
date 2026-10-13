<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 7a (doc 25 §8, §9 rows 11 and 13; doc 05; doc 17; D-069):
 * - audit_events: business audit, written INSIDE the command transaction (rolls back with it), append-only; the actor
 *   and time are stamped by the database from the signed context, never taken from input.
 * - inbox_notifications: persisted per-user inbox (doc 07); a user reads and marks only its own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_events (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              occurred_at timestamptz NOT NULL,
              actor uuid NOT NULL,
              action text NOT NULL CHECK (action ~ '^[a-z][a-z_]*(\.[a-z_]+)+$' AND length(action) <= 64),
              entity_type text NOT NULL CHECK (entity_type ~ '^[a-z][a-z_]{0,63}$'),
              entity_id uuid NOT NULL,
              plant_id uuid,
              before_hash text CHECK (before_hash ~ '^[0-9a-f]{64}$'),
              after_hash text CHECK (after_hash ~ '^[0-9a-f]{64}$'),
              detail jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(detail) = 'object' AND pg_column_size(detail) <= 4096),
              correlation_id text CHECK (correlation_id ~ '^[A-Za-z0-9_-]{8,64}$'),
              ip_hash text CHECK (ip_hash ~ '^[0-9a-f]{64}$'),
              user_agent_hash text CHECK (user_agent_hash ~ '^[0-9a-f]{64}$'),
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );
            CREATE INDEX audit_events_tenant_time_idx ON audit_events (tenant_id, occurred_at DESC);
            CREATE INDEX audit_events_entity_idx ON audit_events (tenant_id, entity_type, entity_id);

            CREATE OR REPLACE FUNCTION eora_stamp_audit_event() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              NEW.actor := a;
              NEW.occurred_at := pg_catalog.now();
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_stamp_audit_event() FROM PUBLIC;
            CREATE TRIGGER stamp_actor BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit_event();
            CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION eora_append_only();
            CREATE TRIGGER append_only_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION eora_append_only();

            ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_insert ON audit_events FOR INSERT TO eora_app
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY tenant_read ON audit_events FOR SELECT TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON audit_events TO eora_app;

            CREATE TABLE inbox_notifications (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              membership_id uuid NOT NULL,
              user_id uuid NOT NULL,
              event_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind ~ '^[a-z][a-z_]*(\.[a-z_]+)+$' AND length(kind) <= 64),
              severity text NOT NULL CHECK (severity IN ('INFO', 'WARNING', 'CRITICAL')),
              action_required boolean NOT NULL DEFAULT false,
              title text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
              body text CHECK (length(body) <= 1000),
              deep_link text CHECK (deep_link ~ '^/[A-Za-z0-9/_.?=&-]{0,299}$'),
              created_at timestamptz NOT NULL DEFAULT now(),
              read_at timestamptz,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (user_id, event_id),
              FOREIGN KEY (tenant_id, membership_id) REFERENCES memberships (tenant_id, id)
            );
            CREATE INDEX inbox_notifications_user_idx ON inbox_notifications (tenant_id, user_id, created_at DESC);

            -- the recipient must be the member the row is addressed to (no notification to a foreign user id)
            CREATE OR REPLACE FUNCTION eora_check_inbox_recipient() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.memberships m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.membership_id AND m.user_id = NEW.user_id) THEN
                RAISE EXCEPTION 'recipient does not match the membership' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_inbox_recipient() FROM PUBLIC;
            CREATE TRIGGER check_recipient BEFORE INSERT ON inbox_notifications FOR EACH ROW EXECUTE FUNCTION eora_check_inbox_recipient();

            ALTER TABLE inbox_notifications ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_insert ON inbox_notifications FOR INSERT TO eora_app
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY own_read ON inbox_notifications FOR SELECT TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND user_id = (SELECT c.actor FROM ctx_api.context() c));
            CREATE POLICY own_mark_read ON inbox_notifications FOR UPDATE TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND user_id = (SELECT c.actor FROM ctx_api.context() c))
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND user_id = (SELECT c.actor FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON inbox_notifications TO eora_app;
            GRANT UPDATE (read_at) ON inbox_notifications TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE inbox_notifications;
            DROP FUNCTION eora_check_inbox_recipient();
            DROP TABLE audit_events;
            DROP FUNCTION eora_stamp_audit_event();
            SQL);
    }
};

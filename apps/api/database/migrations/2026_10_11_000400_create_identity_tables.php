<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 4a (doc 25 §2.1–§2.6, §9 rows 3, 7, 12b; D-057): user profiles, memberships and the durable invitation
 * hand-off. Credentials live in the definer-owned `auth` schema (database/bootstrap/20_auth.sql); this migration only
 * gives the NOLOGIN eora_auth_definer the narrow column grants and policies its functions need.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- global profile: one per identity; no status, no secret (doc 25 §2.2)
            CREATE TABLE users (
              id uuid PRIMARY KEY,
              display_name text NOT NULL CHECK (length(display_name) BETWEEN 1 AND 200),
              locale text NOT NULL DEFAULT 'en' CHECK (locale IN ('en', 'id')),
              email_display text NOT NULL CHECK (length(email_display) BETWEEN 3 AND 254),
              credential_version integer NOT NULL DEFAULT 1,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE memberships (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
              status text NOT NULL DEFAULT 'INVITED' CHECK (status IN ('INVITED', 'ACTIVE', 'SUSPENDED', 'REVOKED')),
              default_plant_id uuid,
              invited_at timestamptz NOT NULL DEFAULT now(),
              activated_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, user_id),
              FOREIGN KEY (tenant_id, default_plant_id) REFERENCES plants (tenant_id, id)
            );
            CREATE INDEX memberships_user_idx ON memberships (user_id, tenant_id);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON memberships FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- durable invitation hand-off to the delivery worker (doc 25 §2.4, R-40); nothing goes through Redis
            CREATE TABLE invitation_dispatch_requests (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              membership_id uuid NOT NULL,
              reason text NOT NULL CHECK (reason IN ('INITIAL', 'RESEND', 'CONSOLE')),
              status text NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'CONVERTED', 'DUPLICATE', 'REJECTED')),
              converted_delivery_id uuid,
              rejected_reason text CHECK (rejected_reason ~ '^[A-Z][A-Z0-9_]{0,63}$'),
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, membership_id) REFERENCES memberships (tenant_id, id)
            );
            CREATE INDEX invitation_dispatch_pending_idx ON invitation_dispatch_requests (created_at) WHERE status = 'PENDING';

            -- users: self + members of the active tenant (memberships is tenant-filtered); own profile only for UPDATE
            ALTER TABLE users ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY profile_read ON users FOR SELECT TO eora_app
              USING (id = (SELECT c.actor FROM ctx_api.context() c) OR id IN (SELECT m.user_id FROM memberships m));
            CREATE POLICY profile_update_own ON users FOR UPDATE TO eora_app
              USING (id = (SELECT c.actor FROM ctx_api.context() c)) WITH CHECK (id = (SELECT c.actor FROM ctx_api.context() c));
            GRANT SELECT (id, display_name, locale, email_display, credential_version) ON users TO eora_app;
            GRANT UPDATE (display_name, locale) ON users TO eora_app;
            -- auth definer: create profiles, bump credential_version, delete orphan profiles only (doc 25 §2.1, R-18/R-34/R-39/R-41)
            CREATE POLICY definer_insert ON users FOR INSERT TO eora_auth_definer WITH CHECK (true);
            CREATE POLICY definer_read_ids ON users FOR SELECT TO eora_auth_definer USING (true);
            CREATE POLICY definer_bump_version ON users FOR UPDATE TO eora_auth_definer USING (true) WITH CHECK (true);
            CREATE POLICY definer_delete_orphan ON users FOR DELETE TO eora_auth_definer
              USING (NOT EXISTS (SELECT 1 FROM public.memberships m WHERE m.user_id = users.id));
            GRANT INSERT (id, display_name, email_display, locale), SELECT (id, credential_version), UPDATE (credential_version), DELETE
              ON users TO eora_auth_definer;

            -- memberships: tenant scope; tenant-less picker context sees the actor's own memberships (doc 25 §2.6)
            ALTER TABLE memberships ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON memberships TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY picker_read ON memberships FOR SELECT TO eora_app
              USING ((SELECT c.tenant FROM ctx_api.context() c) IS NULL AND user_id = (SELECT c.actor FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON memberships TO eora_app;
            CREATE POLICY definer_read ON memberships FOR SELECT TO eora_auth_definer USING (true);
            GRANT SELECT (tenant_id, id, user_id, status, invited_at) ON memberships TO eora_auth_definer;

            -- tenants: the picker also sees tenants where the actor holds an ACTIVE membership
            CREATE POLICY tenant_picker_read ON tenants FOR SELECT TO eora_app
              USING ((SELECT c.tenant FROM ctx_api.context() c) IS NULL
                     AND id IN (SELECT m.tenant_id FROM memberships m WHERE m.status = 'ACTIVE'));

            ALTER TABLE invitation_dispatch_requests ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON invitation_dispatch_requests TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON invitation_dispatch_requests TO eora_app;
            CREATE POLICY definer_dispatch ON invitation_dispatch_requests FOR SELECT TO eora_auth_definer USING (true);
            CREATE POLICY definer_dispatch_update ON invitation_dispatch_requests FOR UPDATE TO eora_auth_definer USING (true) WITH CHECK (true);
            GRANT SELECT (id, tenant_id, membership_id, reason, status, created_at), UPDATE (status, converted_delivery_id, rejected_reason)
              ON invitation_dispatch_requests TO eora_auth_definer;

            -- security events written by auth definer functions: auth sources only, INSERT only (doc 25 §8, R-19)
            CREATE POLICY definer_insert ON security_events FOR INSERT TO eora_auth_definer
              WITH CHECK (source IN ('auth_web', 'auth_delivery'));
            GRANT INSERT (id, occurred_at, tenant_id, user_id, membership_id, source, action, detail_code, ip_hash, user_agent_hash, email_hmac, correlation_id)
              ON security_events TO eora_auth_definer;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL ON security_events FROM eora_auth_definer;
            DROP POLICY definer_insert ON security_events;
            DROP POLICY tenant_picker_read ON tenants;
            DROP POLICY profile_read ON users;
            DROP POLICY definer_delete_orphan ON users;
            DROP TABLE invitation_dispatch_requests;
            DROP TABLE memberships;
            DROP TABLE users;
            SQL);
    }
};

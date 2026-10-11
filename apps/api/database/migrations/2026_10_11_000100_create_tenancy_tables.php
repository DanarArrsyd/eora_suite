<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 2 (doc 25 §1, §2.6, §9 rows 1, 5, 6; ADR-0001 Amendment 2): tenants, companies, plants.
 * Forced RLS bound to the signed context; composite tenant foreign keys; no DELETE for runtime roles.
 * Tenants are created only by eora_provisioner (doc 25 §2.5); the tenant-picker read via memberships comes with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Audit columns (doc 17: created_at, created_by, updated_at, updated_by, version) are stamped by the database
            -- from the signed actor; callers cannot supply or forge them. Invoker trigger, not a definer.
            CREATE OR REPLACE FUNCTION eora_stamp_audit() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                NEW.created_at := pg_catalog.now();
                NEW.created_by := a;
                NEW.version := 1;
              ELSE
                NEW.created_at := OLD.created_at;
                NEW.created_by := OLD.created_by;
                NEW.version := OLD.version + 1;
              END IF;
              NEW.updated_at := pg_catalog.now();
              NEW.updated_by := a;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_stamp_audit() FROM PUBLIC;

            CREATE TABLE tenants (
              id uuid PRIMARY KEY,
              code text NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9][a-z0-9-]{1,39}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              status text NOT NULL DEFAULT 'PROVISIONING' CHECK (status IN ('PROVISIONING', 'ACTIVE', 'SUSPENDED', 'OFFBOARDING')),
              timezone text NOT NULL DEFAULT 'Asia/Jakarta',
              data_region text NOT NULL DEFAULT 'id',
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL
            );
            ALTER TABLE tenants ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            -- a tenant sees only itself
            CREATE POLICY tenant_self_read ON tenants FOR SELECT TO eora_app USING (id = (SELECT c.tenant FROM ctx_api.context() c));
            -- provisioning creates a tenant row in PROVISIONING before any context exists for it (doc 25 §2.5)
            CREATE POLICY tenant_provision_insert ON tenants FOR INSERT TO eora_provisioner WITH CHECK (status = 'PROVISIONING');
            -- status changes only inside that tenant's own signed context
            CREATE POLICY tenant_provision_status ON tenants FOR UPDATE TO eora_provisioner
              USING (id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT ON tenants TO eora_app;
            GRANT INSERT, UPDATE (status) ON tenants TO eora_provisioner;

            CREATE TABLE companies (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              legal_name text NOT NULL CHECK (length(legal_name) BETWEEN 1 AND 200),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code)
            );

            CREATE TABLE plants (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              company_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              timezone text NOT NULL DEFAULT 'Asia/Jakarta',
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code),
              FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id)
            );
            CREATE INDEX plants_tenant_company_idx ON plants (tenant_id, company_id);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON tenants FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON companies FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON plants FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            ALTER TABLE companies ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON companies TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON companies TO eora_app;
            ALTER TABLE plants ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON plants TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON plants TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE plants; DROP TABLE companies; DROP TABLE tenants; DROP FUNCTION eora_stamp_audit();');
    }
};

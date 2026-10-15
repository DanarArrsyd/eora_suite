<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 3 (doc 29 §1.3, doc 26 §2.3–§2.4a, D-058 D-B2-02/04/05/06/08/14/15, D-059, D-060): process types and
 * versioned routings.
 *
 * - `process_types`: tenant-defined process catalogue (no hard-coded list).
 * - `routings`: one per item + plant + code (D-B2-05); PRIMARY or ALTERNATE (D-B2-04), at most one active PRIMARY.
 * - `routing_versions`: DRAFT → SUBMITTED → APPROVED → SUPERSEDED (+ SUBMITTED → DRAFT withdraw, SUBMITTED → REJECTED →
 *   DRAFT). Standard yield SY per version (D-B2-02, a fraction like 0.97). Inclusive effective ranges; approved and
 *   superseded versions never overlap (exclusion constraint, D-B2-08). The preparer is stamped from the signed actor.
 *   Only `lifecycle_api.approve_routing_version` (bootstrap 50_lifecycle.sql, owner eora_lifecycle_definer) may make a
 *   version APPROVED or supersede one; the guard trigger proves it with current_user, which no login role can be.
 *   Approved and superseded versions are immutable otherwise.
 * - `routing_operations` (+ machines, tools, inspections): `op_seq` meaningful only inside its version; work center in
 *   the routing's plant (composite FK); children change only while their version is a DRAFT (trigger); a machine must
 *   sit in its operation's work center and a tool must be a die of the routing's plant linked to the item (triggers,
 *   re-checked at approval). Cycle and setup times are numeric, never float.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE process_types (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9_-]{0,31}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
              category text NOT NULL CHECK (category IN ('TRANSFORMATION', 'INSPECTION', 'HANDLING')),
              default_work_center_type text CHECK (default_work_center_type ~ '^[A-Z][A-Z0-9_]{1,31}$'),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code)
            );

            ALTER TABLE machines ADD CONSTRAINT machines_tenant_wc_id_uq UNIQUE (tenant_id, work_center_id, id);

            CREATE TABLE routings (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              item_id uuid NOT NULL,
              routing_code text NOT NULL CHECK (routing_code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              routing_kind text NOT NULL CHECK (routing_kind IN ('PRIMARY', 'ALTERNATE')),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, plant_id, item_id, routing_code),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );
            CREATE UNIQUE INDEX routings_one_primary_uq ON routings (tenant_id, plant_id, item_id)
              WHERE routing_kind = 'PRIMARY' AND status = 'ACTIVE';

            CREATE TABLE routing_versions (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              routing_id uuid NOT NULL,
              version_no integer NOT NULL CHECK (version_no > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED', 'SUPERSEDED')),
              effective_from date NOT NULL,
              effective_to date,
              change_reason text CHECK (length(change_reason) BETWEEN 1 AND 500),
              standard_yield numeric(6,5) CHECK (standard_yield > 0 AND standard_yield <= 1),
              prepared_by uuid NOT NULL,
              submitted_at timestamptz,
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              approved_by uuid,
              approved_at timestamptz,
              superseded_by_version_id uuid,
              superseded_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, routing_id, version_no),
              FOREIGN KEY (tenant_id, plant_id, routing_id) REFERENCES routings (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, superseded_by_version_id) REFERENCES routing_versions (tenant_id, id),
              CHECK (effective_to IS NULL OR effective_to >= effective_from),
              CHECK ((status IN ('APPROVED', 'SUPERSEDED')) = (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
              CHECK ((status = 'SUPERSEDED') = (superseded_by_version_id IS NOT NULL)),
              -- approved history never overlaps (inclusive bounds, D-B2-08)
              EXCLUDE USING gist (tenant_id WITH =, routing_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)
                WHERE (status IN ('APPROVED', 'SUPERSEDED'))
            );
            CREATE INDEX routing_versions_routing_idx ON routing_versions (tenant_id, routing_id, status);

            CREATE TABLE routing_operations (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              routing_version_id uuid NOT NULL,
              op_seq integer NOT NULL CHECK (op_seq > 0 AND op_seq <= 9999),
              step_key text CHECK (step_key ~ '^[A-Z][A-Z0-9_-]{0,31}$'),
              process_type_id uuid NOT NULL,
              work_center_id uuid NOT NULL,
              description text CHECK (length(description) BETWEEN 1 AND 500),
              std_setup_minutes numeric(10,2) NOT NULL DEFAULT 0 CHECK (std_setup_minutes >= 0),
              std_cycle_seconds numeric(12,3) NOT NULL CHECK (std_cycle_seconds >= 0),
              cycle_basis text NOT NULL CHECK (cycle_basis IN ('PER_PIECE', 'PER_STROKE', 'PER_BATCH')),
              parts_per_cycle integer NOT NULL DEFAULT 1 CHECK (parts_per_cycle BETWEEN 1 AND 100000),
              inspection_required boolean NOT NULL DEFAULT false,
              is_count_point boolean NOT NULL DEFAULT true,
              requires_tooling boolean NOT NULL DEFAULT false,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, work_center_id, id),
              UNIQUE (tenant_id, routing_version_id, op_seq),
              FOREIGN KEY (tenant_id, plant_id, routing_version_id) REFERENCES routing_versions (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, plant_id, work_center_id) REFERENCES work_centers (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, process_type_id) REFERENCES process_types (tenant_id, id)
            );

            CREATE TABLE routing_operation_machines (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              operation_id uuid NOT NULL,
              machine_id uuid NOT NULL,
              is_preferred boolean NOT NULL DEFAULT false,
              cycle_seconds_override numeric(12,3) CHECK (cycle_seconds_override >= 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, operation_id, machine_id),
              FOREIGN KEY (tenant_id, operation_id) REFERENCES routing_operations (tenant_id, id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, machine_id) REFERENCES machines (tenant_id, id)
            );
            CREATE UNIQUE INDEX routing_operation_machines_preferred_uq ON routing_operation_machines (tenant_id, operation_id) WHERE is_preferred;

            CREATE TABLE routing_operation_tools (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              operation_id uuid NOT NULL,
              die_id uuid NOT NULL,
              quantity integer NOT NULL DEFAULT 1 CHECK (quantity BETWEEN 1 AND 100),
              cavities_used integer CHECK (cavities_used BETWEEN 1 AND 1000),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, operation_id, die_id),
              FOREIGN KEY (tenant_id, operation_id) REFERENCES routing_operations (tenant_id, id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, die_id) REFERENCES dies (tenant_id, id)
            );

            CREATE TABLE routing_operation_inspections (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              operation_id uuid NOT NULL,
              characteristic text NOT NULL CHECK (length(characteristic) BETWEEN 1 AND 200),
              sampling_note text CHECK (length(sampling_note) BETWEEN 1 AND 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              FOREIGN KEY (tenant_id, operation_id) REFERENCES routing_operations (tenant_id, id) ON DELETE CASCADE
            );

            -- Version lifecycle guard (doc 26 §2.4a). Invoker trigger: current_user is the role running the statement, so
            -- only code inside lifecycle_api (owner eora_lifecycle_definer) passes the approval branches.
            CREATE OR REPLACE FUNCTION eora_guard_routing_version() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              definer boolean := (current_user = 'eora_lifecycle_definer');
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_version_id IS NOT NULL
                   OR NEW.submitted_at IS NOT NULL OR NEW.reject_reason IS NOT NULL OR NEW.effective_to IS NOT NULL THEN
                  RAISE EXCEPTION 'a routing version starts as an open-ended DRAFT' USING ERRCODE = '23514';
                END IF;
                NEW.prepared_by := (SELECT c.actor FROM ctx_api.context() c);
                IF NEW.prepared_by IS NULL THEN
                  RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;

              -- identity never changes
              IF (NEW.tenant_id, NEW.plant_id, NEW.routing_id, NEW.version_no, NEW.prepared_by)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.routing_id, OLD.version_no, OLD.prepared_by) THEN
                RAISE EXCEPTION 'routing version identity is fixed' USING ERRCODE = '23514';
              END IF;

              IF OLD.status IN ('APPROVED', 'SUPERSEDED') THEN
                -- the one restricted transition: APPROVED → SUPERSEDED inside the lifecycle function
                IF NOT definer OR OLD.status <> 'APPROVED' OR NEW.status <> 'SUPERSEDED'
                   OR NEW.effective_to IS NULL OR NEW.effective_to < OLD.effective_from
                   OR (OLD.effective_to IS NOT NULL AND NEW.effective_to > OLD.effective_to)
                   OR (NEW.effective_from, NEW.change_reason, NEW.standard_yield, NEW.submitted_at, NEW.reject_reason, NEW.approved_by, NEW.approved_at)
                      IS DISTINCT FROM (OLD.effective_from, OLD.change_reason, OLD.standard_yield, OLD.submitted_at, OLD.reject_reason, OLD.approved_by, OLD.approved_at) THEN
                  RAISE EXCEPTION 'approved routing versions are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.status IN ('APPROVED', 'SUPERSEDED') THEN
                IF NOT definer OR OLD.status <> 'SUBMITTED' OR NEW.status <> 'APPROVED' THEN
                  RAISE EXCEPTION 'only the routing approval function approves a version' USING ERRCODE = '23514';
                END IF;
                IF (NEW.effective_from, NEW.effective_to, NEW.change_reason, NEW.standard_yield) IS DISTINCT FROM (OLD.effective_from, OLD.effective_to, OLD.change_reason, OLD.standard_yield) THEN
                  RAISE EXCEPTION 'approval changes nothing but the status' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;

              -- ordinary lifecycle, any runtime role: edit a DRAFT, submit, withdraw, reject, reopen a rejection
              IF NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_version_id IS NOT NULL OR NEW.effective_to IS NOT NULL THEN
                RAISE EXCEPTION 'approval fields are set only by the approval function' USING ERRCODE = '23514';
              END IF;
              IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('DRAFT', 'SUBMITTED'))
                      OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('DRAFT', 'REJECTED'))
                      OR (OLD.status = 'REJECTED' AND NEW.status = 'DRAFT')) THEN
                RAISE EXCEPTION 'invalid routing version transition % → %', OLD.status, NEW.status USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT'
                 AND (NEW.effective_from, NEW.change_reason, NEW.standard_yield) IS DISTINCT FROM (OLD.effective_from, OLD.change_reason, OLD.standard_yield) THEN
                RAISE EXCEPTION 'only a DRAFT version is edited' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_routing_version() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON routing_versions FOR EACH ROW EXECUTE FUNCTION eora_guard_routing_version();

            -- operations and their children change only while their version is a DRAFT
            CREATE OR REPLACE FUNCTION eora_guard_routing_content() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              op_id uuid;
              ver_status text;
              row_data record;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                row_data := OLD;
              ELSE
                row_data := NEW;
              END IF;
              IF TG_TABLE_NAME = 'routing_operations' THEN
                SELECT v.status INTO ver_status FROM public.routing_versions v WHERE v.id = row_data.routing_version_id;
              ELSE
                op_id := row_data.operation_id;
                SELECT v.status INTO ver_status
                  FROM public.routing_operations o JOIN public.routing_versions v ON v.id = o.routing_version_id
                 WHERE o.id = op_id;
              END IF;
              -- a cascade from a deleted DRAFT operation finds no operation any more: nothing to protect
              IF ver_status IS NOT NULL AND ver_status <> 'DRAFT' THEN
                RAISE EXCEPTION 'operations change only while their version is a DRAFT' USING ERRCODE = '23514';
              END IF;
              RETURN row_data;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_routing_content() FROM PUBLIC;
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON routing_operations FOR EACH ROW EXECUTE FUNCTION eora_guard_routing_content();
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON routing_operation_machines FOR EACH ROW EXECUTE FUNCTION eora_guard_routing_content();
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON routing_operation_tools FOR EACH ROW EXECUTE FUNCTION eora_guard_routing_content();
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON routing_operation_inspections FOR EACH ROW EXECUTE FUNCTION eora_guard_routing_content();

            -- Maker ≠ checker covers every author, not only the preparer: each actor who creates, edits, submits,
            -- withdraws or reopens a version — or changes its operations — is recorded here by the database (actor from
            -- the signed context, never a parameter). Reviewing (SUBMITTED → REJECTED) and the lifecycle function do not
            -- count. Rows are never updated or deleted; a direct INSERT can only add the caller itself.
            CREATE TABLE routing_version_contributors (
              tenant_id uuid NOT NULL,
              routing_version_id uuid NOT NULL,
              actor uuid NOT NULL,
              first_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, routing_version_id, actor),
              FOREIGN KEY (tenant_id, routing_version_id) REFERENCES routing_versions (tenant_id, id)
            );
            ALTER TABLE routing_version_contributors ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_read ON routing_version_contributors FOR SELECT TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY self_insert ON routing_version_contributors FOR INSERT TO eora_app
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND actor = (SELECT c.actor FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON routing_version_contributors TO eora_app;

            CREATE OR REPLACE FUNCTION eora_note_routing_contributor() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              row_data record;
              ver_id uuid;
              ctx_tenant uuid;
              ctx_actor uuid;
            BEGIN
              IF current_user = 'eora_lifecycle_definer' THEN
                RETURN NULL;
              END IF;
              IF TG_OP = 'DELETE' THEN
                row_data := OLD;
              ELSE
                row_data := NEW;
              END IF;
              IF TG_TABLE_NAME = 'routing_versions' THEN
                IF TG_OP = 'UPDATE' AND NEW.status = 'REJECTED' THEN
                  RETURN NULL;
                END IF;
                ver_id := row_data.id;
              ELSIF TG_TABLE_NAME = 'routing_operations' THEN
                ver_id := row_data.routing_version_id;
              ELSE
                SELECT o.routing_version_id INTO ver_id FROM public.routing_operations o WHERE o.id = row_data.operation_id;
              END IF;
              -- a cascade from a deleted operation: the operation's own delete was recorded
              IF ver_id IS NULL THEN
                RETURN NULL;
              END IF;
              SELECT c.tenant, c.actor INTO ctx_tenant, ctx_actor FROM ctx_api.context() c;
              IF ctx_actor IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              INSERT INTO public.routing_version_contributors (tenant_id, routing_version_id, actor)
                VALUES (ctx_tenant, ver_id, ctx_actor) ON CONFLICT DO NOTHING;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_note_routing_contributor() FROM PUBLIC;
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE ON routing_versions FOR EACH ROW EXECUTE FUNCTION eora_note_routing_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON routing_operations FOR EACH ROW EXECUTE FUNCTION eora_note_routing_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON routing_operation_machines FOR EACH ROW EXECUTE FUNCTION eora_note_routing_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON routing_operation_tools FOR EACH ROW EXECUTE FUNCTION eora_note_routing_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON routing_operation_inspections FOR EACH ROW EXECUTE FUNCTION eora_note_routing_contributor();

            -- eligible machine: in its operation's work center
            CREATE OR REPLACE FUNCTION eora_check_operation_machine() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.routing_operations o JOIN public.machines m ON m.work_center_id = o.work_center_id
                              WHERE o.id = NEW.operation_id AND m.id = NEW.machine_id) THEN
                RAISE EXCEPTION 'the machine is not in the operation''s work center' USING ERRCODE = 'EO013';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_operation_machine() FROM PUBLIC;
            CREATE TRIGGER check_machine BEFORE INSERT OR UPDATE ON routing_operation_machines FOR EACH ROW EXECUTE FUNCTION eora_check_operation_machine();

            -- eligible tool: a die of the routing's plant, linked to the routing's item (doc 26 §2.4)
            CREATE OR REPLACE FUNCTION eora_check_operation_tool() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM public.routing_operations o
                  JOIN public.routing_versions v ON v.id = o.routing_version_id
                  JOIN public.routings r ON r.id = v.routing_id
                  JOIN public.dies d ON d.id = NEW.die_id AND d.plant_id = r.plant_id
                  JOIN public.die_items di ON di.die_id = d.id AND di.item_id = r.item_id AND di.status = 'ACTIVE'
                 WHERE o.id = NEW.operation_id
              ) THEN
                RAISE EXCEPTION 'the die is not in the routing''s plant or not linked to the item' USING ERRCODE = 'EO013';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_operation_tool() FROM PUBLIC;
            CREATE TRIGGER check_tool BEFORE INSERT OR UPDATE ON routing_operation_tools FOR EACH ROW EXECUTE FUNCTION eora_check_operation_tool();

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON process_types FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routings FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routing_versions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routing_operations FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routing_operation_machines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routing_operation_tools FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON routing_operation_inspections FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['process_types', 'routings', 'routing_versions', 'routing_operations',
                                       'routing_operation_machines', 'routing_operation_tools', 'routing_operation_inspections'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            GRANT UPDATE (name, category, default_work_center_type, status) ON process_types TO eora_app;
            GRANT UPDATE (status) ON routings TO eora_app;
            GRANT UPDATE (status, effective_from, effective_to, change_reason, standard_yield, submitted_at, reject_reason,
                          approved_by, approved_at, superseded_by_version_id, superseded_at) ON routing_versions TO eora_app;
            GRANT DELETE ON routing_operations, routing_operation_machines, routing_operation_tools, routing_operation_inspections TO eora_app;

            -- Routing-approval definer (doc 26 §2.4a): member of no role; exactly the columns the approval reads and writes,
            -- through tenant policies bound to the signed context like eora_app's (never wider). Its only writes: the
            -- approval/supersession columns of routing_versions and its two audit events. UPDATE (status) on routings only
            -- makes the SELECT … FOR UPDATE lock possible; the function never updates a routing.
            GRANT SELECT (id, timezone, status) ON tenants TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, company_id) ON plants TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, user_id, status) ON memberships TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, membership_id, role_id, scope_type, company_id, plant_id, valid_from, valid_until, revoked_at) ON role_assignments TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, role_id, permission_code) ON role_permissions TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, work_center_id) ON machines TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, plant_id) ON dies TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, die_id, item_id, status) ON die_items TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, plant_id, item_id) ON routings TO eora_lifecycle_definer;
            GRANT UPDATE (status) ON routings TO eora_lifecycle_definer;
            GRANT SELECT ON routing_versions TO eora_lifecycle_definer;
            GRANT UPDATE (status, effective_to, superseded_by_version_id, superseded_at, approved_by, approved_at) ON routing_versions TO eora_lifecycle_definer;
            GRANT SELECT (id, tenant_id, routing_version_id, work_center_id) ON routing_operations TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, operation_id, machine_id) ON routing_operation_machines TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, operation_id, die_id) ON routing_operation_tools TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, routing_version_id, actor) ON routing_version_contributors TO eora_lifecycle_definer;
            GRANT INSERT (tenant_id, action, entity_type, entity_id, plant_id, before_hash, after_hash, detail) ON audit_events TO eora_lifecycle_definer;
            CREATE POLICY lifecycle_definer ON tenants TO eora_lifecycle_definer USING (id = (SELECT c.tenant FROM ctx_api.context() c));
            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['plants', 'memberships', 'role_assignments', 'role_permissions', 'machines', 'dies', 'die_items',
                                       'routings', 'routing_versions', 'routing_operations', 'routing_operation_machines',
                                       'routing_operation_tools', 'routing_version_contributors', 'audit_events'] LOOP
                EXECUTE format('CREATE POLICY lifecycle_definer ON %I TO eora_lifecycle_definer USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
              END LOOP;
            END
            $do$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['tenants', 'plants', 'memberships', 'role_assignments', 'role_permissions', 'machines', 'dies', 'die_items', 'audit_events'] LOOP
                EXECUTE format('DROP POLICY lifecycle_definer ON %I', t);
              END LOOP;
            END
            $do$;
            REVOKE ALL ON tenants, plants, memberships, role_assignments, role_permissions, machines, dies, die_items, audit_events FROM eora_lifecycle_definer;
            DROP TABLE routing_version_contributors;
            DROP TABLE routing_operation_inspections;
            DROP TABLE routing_operation_tools;
            DROP TABLE routing_operation_machines;
            DROP TABLE routing_operations;
            DROP TABLE routing_versions;
            DROP TABLE routings;
            DROP FUNCTION eora_note_routing_contributor();
            DROP FUNCTION eora_check_operation_tool();
            DROP FUNCTION eora_check_operation_machine();
            DROP FUNCTION eora_guard_routing_content();
            DROP FUNCTION eora_guard_routing_version();
            ALTER TABLE machines DROP CONSTRAINT machines_tenant_wc_id_uq;
            DROP TABLE process_types;
            SQL);
    }
};

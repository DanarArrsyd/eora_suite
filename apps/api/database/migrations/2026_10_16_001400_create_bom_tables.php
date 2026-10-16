<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 4 (doc 29 §1.4, doc 26 §2.5, D-058 D-B2-01 revised, D-046 O-026, D-059, D-078, D-079): bills of material and
 * the BOM–routing map.
 *
 * - `bom_versions`: per item, tenant-wide (D-078: maintained with `master.routing.*` in any scope). Same lifecycle as
 *   routing versions: DRAFT → SUBMITTED → APPROVED → SUPERSEDED (+ withdraw, reject, reopen); inclusive effective ranges
 *   that never overlap once approved; only `lifecycle_api.approve_bom_version` approves or supersedes (current_user).
 * - `bom_lines`: `(bom_version_id, line_no)` is one requirement definition; a component may appear on several lines
 *   (D-B2-01); `gross_qty_per` per finished piece in the line's material UOM (O-026); scrap allowance applied once
 *   (BR-PLN-06); never the BOM's own item. Lines change only while their version is a DRAFT.
 * - `bom_routing_maps` + lines: each BOM line → exactly one operation of one routing version, per (BOM version, routing
 *   version) pair and revision. Composite FKs keep the BOM's item = the routing's item and every line inside the pair
 *   (B2-AT-53..55). A map is drafted only for versions whose content is locked (SUBMITTED or APPROVED) and approved only
 *   when both are APPROVED (D-079); at most one APPROVED map per pair; approval and supersession through
 *   `lifecycle_api.approve_bom_routing_map` (maker ≠ checker, plant scope of the routing).
 * - Maker ≠ checker covers every author: `*_contributors` record the signed actor of each edit (insert-self only).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- targets of the map's composite FKs (doc 26 §2.5 "each target has the matching UNIQUE")
            ALTER TABLE routings ADD CONSTRAINT routings_tenant_id_item_uq UNIQUE (tenant_id, id, item_id);
            ALTER TABLE routing_versions ADD CONSTRAINT routing_versions_tenant_id_routing_uq UNIQUE (tenant_id, id, routing_id);
            ALTER TABLE routing_operations ADD CONSTRAINT routing_operations_tenant_id_version_uq UNIQUE (tenant_id, id, routing_version_id);

            CREATE TABLE bom_versions (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              version_no integer NOT NULL CHECK (version_no > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED', 'SUPERSEDED')),
              effective_from date NOT NULL,
              effective_to date,
              change_reason text CHECK (length(change_reason) BETWEEN 1 AND 500),
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
              UNIQUE (tenant_id, id, item_id),
              UNIQUE (tenant_id, item_id, version_no),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, superseded_by_version_id) REFERENCES bom_versions (tenant_id, id),
              CHECK (effective_to IS NULL OR effective_to >= effective_from),
              CHECK ((status IN ('APPROVED', 'SUPERSEDED')) = (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
              CHECK ((status = 'SUPERSEDED') = (superseded_by_version_id IS NOT NULL)),
              EXCLUDE USING gist (tenant_id WITH =, item_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)
                WHERE (status IN ('APPROVED', 'SUPERSEDED'))
            );
            CREATE INDEX bom_versions_item_idx ON bom_versions (tenant_id, item_id, status);

            CREATE TABLE bom_lines (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              bom_version_id uuid NOT NULL,
              line_no integer NOT NULL CHECK (line_no BETWEEN 1 AND 9999),
              component_item_id uuid NOT NULL,
              gross_qty_per numeric(18,6) NOT NULL CHECK (gross_qty_per > 0),
              uom_id uuid NOT NULL,
              scrap_allowance numeric(6,5) NOT NULL DEFAULT 0 CHECK (scrap_allowance >= 0 AND scrap_allowance < 1),
              -- conversion profile reference arrives with M2 step 5 (FK added there)
              conversion_profile_id uuid,
              line_purpose text CHECK (length(line_purpose) BETWEEN 1 AND 200),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, bom_version_id),
              UNIQUE (tenant_id, bom_version_id, line_no),
              FOREIGN KEY (tenant_id, bom_version_id) REFERENCES bom_versions (tenant_id, id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, component_item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id)
            );
            CREATE INDEX bom_lines_component_idx ON bom_lines (tenant_id, component_item_id);

            CREATE TABLE bom_routing_maps (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              plant_id uuid NOT NULL,
              bom_version_id uuid NOT NULL,
              routing_id uuid NOT NULL,
              routing_version_id uuid NOT NULL,
              map_revision integer NOT NULL CHECK (map_revision > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED', 'SUPERSEDED')),
              change_reason text CHECK (length(change_reason) BETWEEN 1 AND 500),
              prepared_by uuid NOT NULL,
              submitted_at timestamptz,
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              approved_by uuid,
              approved_at timestamptz,
              superseded_by_map_id uuid,
              superseded_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, bom_version_id, routing_version_id),
              UNIQUE (tenant_id, bom_version_id, routing_version_id, map_revision),
              -- one item for the BOM and the routing (B2-AT-53/54)
              FOREIGN KEY (tenant_id, bom_version_id, item_id) REFERENCES bom_versions (tenant_id, id, item_id),
              FOREIGN KEY (tenant_id, routing_version_id, routing_id) REFERENCES routing_versions (tenant_id, id, routing_id),
              FOREIGN KEY (tenant_id, routing_id, item_id) REFERENCES routings (tenant_id, id, item_id),
              FOREIGN KEY (tenant_id, plant_id, routing_id) REFERENCES routings (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, superseded_by_map_id) REFERENCES bom_routing_maps (tenant_id, id),
              CHECK ((status IN ('APPROVED', 'SUPERSEDED')) = (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
              CHECK ((status = 'SUPERSEDED') = (superseded_by_map_id IS NOT NULL))
            );
            -- at most one approved map per (BOM version, routing version) pair (B2-AT-44)
            CREATE UNIQUE INDEX bom_routing_maps_one_approved_uq ON bom_routing_maps (tenant_id, bom_version_id, routing_version_id)
              WHERE status = 'APPROVED';
            CREATE INDEX bom_routing_maps_routing_idx ON bom_routing_maps (tenant_id, routing_version_id);

            CREATE TABLE bom_routing_map_lines (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              map_id uuid NOT NULL,
              bom_version_id uuid NOT NULL,
              routing_version_id uuid NOT NULL,
              bom_line_id uuid NOT NULL,
              routing_operation_id uuid NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              -- each BOM line maps to exactly one operation per map revision
              UNIQUE (tenant_id, map_id, bom_line_id),
              FOREIGN KEY (tenant_id, map_id, bom_version_id, routing_version_id)
                REFERENCES bom_routing_maps (tenant_id, id, bom_version_id, routing_version_id),
              -- lines stay inside the pair (B2-AT-55); a draft version's edit removes its stale map lines
              FOREIGN KEY (tenant_id, bom_line_id, bom_version_id) REFERENCES bom_lines (tenant_id, id, bom_version_id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, routing_operation_id, routing_version_id)
                REFERENCES routing_operations (tenant_id, id, routing_version_id) ON DELETE CASCADE
            );

            CREATE TABLE bom_version_contributors (
              tenant_id uuid NOT NULL,
              bom_version_id uuid NOT NULL,
              actor uuid NOT NULL,
              first_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, bom_version_id, actor),
              FOREIGN KEY (tenant_id, bom_version_id) REFERENCES bom_versions (tenant_id, id)
            );
            CREATE TABLE bom_map_contributors (
              tenant_id uuid NOT NULL,
              map_id uuid NOT NULL,
              actor uuid NOT NULL,
              first_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, map_id, actor),
              FOREIGN KEY (tenant_id, map_id) REFERENCES bom_routing_maps (tenant_id, id)
            );

            -- BOM version lifecycle: the routing-version rules (migration 001300) for an item-level version
            CREATE OR REPLACE FUNCTION eora_guard_bom_version() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              definer boolean := (current_user = 'eora_lifecycle_definer');
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_version_id IS NOT NULL
                   OR NEW.submitted_at IS NOT NULL OR NEW.reject_reason IS NOT NULL OR NEW.effective_to IS NOT NULL THEN
                  RAISE EXCEPTION 'a BOM version starts as an open-ended DRAFT' USING ERRCODE = '23514';
                END IF;
                NEW.prepared_by := (SELECT c.actor FROM ctx_api.context() c);
                IF NEW.prepared_by IS NULL THEN
                  RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.item_id, NEW.version_no, NEW.prepared_by) IS DISTINCT FROM (OLD.tenant_id, OLD.item_id, OLD.version_no, OLD.prepared_by) THEN
                RAISE EXCEPTION 'BOM version identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('APPROVED', 'SUPERSEDED') THEN
                IF NOT definer OR OLD.status <> 'APPROVED' OR NEW.status <> 'SUPERSEDED'
                   OR NEW.effective_to IS NULL OR NEW.effective_to < OLD.effective_from
                   OR (OLD.effective_to IS NOT NULL AND NEW.effective_to > OLD.effective_to)
                   OR (NEW.effective_from, NEW.change_reason, NEW.submitted_at, NEW.reject_reason, NEW.approved_by, NEW.approved_at)
                      IS DISTINCT FROM (OLD.effective_from, OLD.change_reason, OLD.submitted_at, OLD.reject_reason, OLD.approved_by, OLD.approved_at) THEN
                  RAISE EXCEPTION 'approved BOM versions are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.status IN ('APPROVED', 'SUPERSEDED') THEN
                IF NOT definer OR OLD.status <> 'SUBMITTED' OR NEW.status <> 'APPROVED' THEN
                  RAISE EXCEPTION 'only the BOM approval function approves a version' USING ERRCODE = '23514';
                END IF;
                IF (NEW.effective_from, NEW.effective_to, NEW.change_reason) IS DISTINCT FROM (OLD.effective_from, OLD.effective_to, OLD.change_reason) THEN
                  RAISE EXCEPTION 'approval changes nothing but the status' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_version_id IS NOT NULL OR NEW.effective_to IS NOT NULL THEN
                RAISE EXCEPTION 'approval fields are set only by the approval function' USING ERRCODE = '23514';
              END IF;
              IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('DRAFT', 'SUBMITTED'))
                      OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('DRAFT', 'REJECTED'))
                      OR (OLD.status = 'REJECTED' AND NEW.status = 'DRAFT')) THEN
                RAISE EXCEPTION 'invalid BOM version transition % → %', OLD.status, NEW.status USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT' AND (NEW.effective_from, NEW.change_reason) IS DISTINCT FROM (OLD.effective_from, OLD.change_reason) THEN
                RAISE EXCEPTION 'only a DRAFT version is edited' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_bom_version() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON bom_versions FOR EACH ROW EXECUTE FUNCTION eora_guard_bom_version();

            -- BOM lines: only while their version is a DRAFT; never the BOM's own item
            CREATE OR REPLACE FUNCTION eora_guard_bom_line() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              row_data record;
              ver record;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                row_data := OLD;
              ELSE
                row_data := NEW;
              END IF;
              SELECT v.status, v.item_id INTO ver FROM public.bom_versions v WHERE v.id = row_data.bom_version_id;
              IF ver.status IS NOT NULL AND ver.status <> 'DRAFT' THEN
                RAISE EXCEPTION 'BOM lines change only while their version is a DRAFT' USING ERRCODE = '23514';
              END IF;
              IF TG_OP <> 'DELETE' AND NEW.component_item_id = ver.item_id THEN
                RAISE EXCEPTION 'an item is not a component of its own BOM' USING ERRCODE = 'EO013';
              END IF;
              RETURN row_data;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_bom_line() FROM PUBLIC;
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON bom_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_bom_line();

            -- Map lifecycle: drafted only for locked versions (D-079); same transitions; approval by the definer only
            CREATE OR REPLACE FUNCTION eora_guard_bom_map() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              definer boolean := (current_user = 'eora_lifecycle_definer');
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_map_id IS NOT NULL
                   OR NEW.submitted_at IS NOT NULL OR NEW.reject_reason IS NOT NULL THEN
                  RAISE EXCEPTION 'a map starts as a DRAFT' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.bom_versions b WHERE b.id = NEW.bom_version_id AND b.status IN ('SUBMITTED', 'APPROVED'))
                   OR NOT EXISTS (SELECT 1 FROM public.routing_versions r WHERE r.id = NEW.routing_version_id AND r.status IN ('SUBMITTED', 'APPROVED')) THEN
                  RAISE EXCEPTION 'a map is drafted for submitted or approved versions only' USING ERRCODE = 'EO012';
                END IF;
                NEW.prepared_by := (SELECT c.actor FROM ctx_api.context() c);
                IF NEW.prepared_by IS NULL THEN
                  RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.item_id, NEW.plant_id, NEW.bom_version_id, NEW.routing_id, NEW.routing_version_id, NEW.map_revision, NEW.prepared_by)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.item_id, OLD.plant_id, OLD.bom_version_id, OLD.routing_id, OLD.routing_version_id, OLD.map_revision, OLD.prepared_by) THEN
                RAISE EXCEPTION 'map identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('APPROVED', 'SUPERSEDED') THEN
                IF NOT definer OR OLD.status <> 'APPROVED' OR NEW.status <> 'SUPERSEDED'
                   OR (NEW.change_reason, NEW.submitted_at, NEW.reject_reason, NEW.approved_by, NEW.approved_at)
                      IS DISTINCT FROM (OLD.change_reason, OLD.submitted_at, OLD.reject_reason, OLD.approved_by, OLD.approved_at) THEN
                  RAISE EXCEPTION 'approved maps are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.status IN ('APPROVED', 'SUPERSEDED') THEN
                IF NOT definer OR OLD.status <> 'SUBMITTED' OR NEW.status <> 'APPROVED' OR NEW.change_reason IS DISTINCT FROM OLD.change_reason THEN
                  RAISE EXCEPTION 'only the map approval function approves a map' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.superseded_by_map_id IS NOT NULL THEN
                RAISE EXCEPTION 'approval fields are set only by the approval function' USING ERRCODE = '23514';
              END IF;
              IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('DRAFT', 'SUBMITTED'))
                      OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('DRAFT', 'REJECTED'))
                      OR (OLD.status = 'REJECTED' AND NEW.status = 'DRAFT')) THEN
                RAISE EXCEPTION 'invalid map transition % → %', OLD.status, NEW.status USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT' AND NEW.change_reason IS DISTINCT FROM OLD.change_reason THEN
                RAISE EXCEPTION 'only a DRAFT map is edited' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_bom_map() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON bom_routing_maps FOR EACH ROW EXECUTE FUNCTION eora_guard_bom_map();

            -- Map lines: only while the map is a DRAFT. Exception: a DRAFT BOM or routing version replacing its lines or
            -- operations removes the map lines that pointed at them (cascade) — such a map can only be approved again once
            -- complete and both versions are APPROVED (lifecycle function).
            CREATE OR REPLACE FUNCTION eora_guard_bom_map_line() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              row_data record;
              map_status text;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                row_data := OLD;
              ELSE
                row_data := NEW;
              END IF;
              SELECT m.status INTO map_status FROM public.bom_routing_maps m WHERE m.id = row_data.map_id;
              IF map_status IS NULL OR map_status = 'DRAFT' THEN
                RETURN row_data;
              END IF;
              IF TG_OP = 'DELETE' AND map_status NOT IN ('APPROVED', 'SUPERSEDED') AND (
                   EXISTS (SELECT 1 FROM public.bom_versions b WHERE b.id = OLD.bom_version_id AND b.status = 'DRAFT')
                   OR EXISTS (SELECT 1 FROM public.routing_versions r WHERE r.id = OLD.routing_version_id AND r.status = 'DRAFT')) THEN
                RETURN row_data;
              END IF;
              RAISE EXCEPTION 'map lines change only while the map is a DRAFT' USING ERRCODE = '23514';
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_bom_map_line() FROM PUBLIC;
            CREATE TRIGGER guard_draft BEFORE INSERT OR UPDATE OR DELETE ON bom_routing_map_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_bom_map_line();

            -- Contributors (maker ≠ checker covers every author, as for routing versions)
            CREATE OR REPLACE FUNCTION eora_note_bom_contributor() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              row_data record;
              target uuid;
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
              -- reviewing (→ REJECTED) is not authorship; nested IFs because NEW of a line row has no status field
              IF TG_TABLE_NAME IN ('bom_versions', 'bom_routing_maps') AND TG_OP = 'UPDATE' THEN
                IF NEW.status = 'REJECTED' THEN
                  RETURN NULL;
                END IF;
              END IF;
              -- Removing map lines is not recorded: it also happens through the FK cascade of a draft version's edit (run as
              -- the table owner, without a signed context), and a map missing a line can never be approved. Adding or
              -- changing a line, and every map row change, is recorded.
              IF TG_TABLE_NAME = 'bom_routing_map_lines' AND TG_OP = 'DELETE' THEN
                RETURN NULL;
              END IF;
              SELECT c.tenant, c.actor INTO ctx_tenant, ctx_actor FROM ctx_api.context() c;
              IF ctx_actor IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              -- (separate branches: plpgsql resolves every field named in an expression, even in an untaken CASE arm)
              IF TG_TABLE_NAME = 'bom_versions' THEN
                target := row_data.id;
              ELSIF TG_TABLE_NAME = 'bom_lines' THEN
                target := row_data.bom_version_id;
              ELSIF TG_TABLE_NAME = 'bom_routing_maps' THEN
                target := row_data.id;
              ELSE
                target := row_data.map_id;
              END IF;
              IF TG_TABLE_NAME IN ('bom_versions', 'bom_lines') THEN
                -- a cascade from a deleted version leaves nothing to record
                IF EXISTS (SELECT 1 FROM public.bom_versions v WHERE v.id = target) THEN
                  INSERT INTO public.bom_version_contributors (tenant_id, bom_version_id, actor) VALUES (ctx_tenant, target, ctx_actor) ON CONFLICT DO NOTHING;
                END IF;
              ELSE
                INSERT INTO public.bom_map_contributors (tenant_id, map_id, actor) VALUES (ctx_tenant, target, ctx_actor) ON CONFLICT DO NOTHING;
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_note_bom_contributor() FROM PUBLIC;
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE ON bom_versions FOR EACH ROW EXECUTE FUNCTION eora_note_bom_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON bom_lines FOR EACH ROW EXECUTE FUNCTION eora_note_bom_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE ON bom_routing_maps FOR EACH ROW EXECUTE FUNCTION eora_note_bom_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE OR DELETE ON bom_routing_map_lines FOR EACH ROW EXECUTE FUNCTION eora_note_bom_contributor();

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON bom_versions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON bom_lines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON bom_routing_maps FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON bom_routing_map_lines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['bom_versions', 'bom_lines', 'bom_routing_maps', 'bom_routing_map_lines'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
              FOREACH t IN ARRAY ARRAY['bom_version_contributors', 'bom_map_contributors'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_read ON %I FOR SELECT TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('CREATE POLICY self_insert ON %I FOR INSERT TO eora_app WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND actor = (SELECT c.actor FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            GRANT UPDATE (status, effective_from, effective_to, change_reason, submitted_at, reject_reason, approved_by, approved_at,
                          superseded_by_version_id, superseded_at) ON bom_versions TO eora_app;
            GRANT UPDATE (status, change_reason, submitted_at, reject_reason, approved_by, approved_at, superseded_by_map_id, superseded_at)
              ON bom_routing_maps TO eora_app;
            GRANT DELETE ON bom_lines, bom_routing_map_lines TO eora_app;

            -- Lifecycle definer (doc 26 §2.4a/§2.5; D-078 any-scope BOM approval, D-079 map rules): exactly what the two
            -- approval functions read and write, through policies bound to the signed tenant.
            GRANT SELECT ON bom_versions, bom_routing_maps TO eora_lifecycle_definer;
            GRANT UPDATE (status, effective_to, superseded_by_version_id, superseded_at, approved_by, approved_at) ON bom_versions TO eora_lifecycle_definer;
            GRANT UPDATE (status, superseded_by_map_id, superseded_at, approved_by, approved_at) ON bom_routing_maps TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, id, bom_version_id, component_item_id) ON bom_lines TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, map_id, bom_line_id) ON bom_routing_map_lines TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, bom_version_id, actor) ON bom_version_contributors TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, map_id, actor) ON bom_map_contributors TO eora_lifecycle_definer;
            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['bom_versions', 'bom_lines', 'bom_routing_maps', 'bom_routing_map_lines', 'bom_version_contributors', 'bom_map_contributors'] LOOP
                EXECUTE format('CREATE POLICY lifecycle_definer ON %I TO eora_lifecycle_definer USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
              END LOOP;
            END
            $do$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE bom_map_contributors;
            DROP TABLE bom_version_contributors;
            DROP TABLE bom_routing_map_lines;
            DROP TABLE bom_routing_maps;
            DROP TABLE bom_lines;
            DROP TABLE bom_versions;
            DROP FUNCTION eora_note_bom_contributor();
            DROP FUNCTION eora_guard_bom_map_line();
            DROP FUNCTION eora_guard_bom_map();
            DROP FUNCTION eora_guard_bom_line();
            DROP FUNCTION eora_guard_bom_version();
            ALTER TABLE routing_operations DROP CONSTRAINT routing_operations_tenant_id_version_uq;
            ALTER TABLE routing_versions DROP CONSTRAINT routing_versions_tenant_id_routing_uq;
            ALTER TABLE routings DROP CONSTRAINT routings_tenant_id_item_uq;
            SQL);
    }
};

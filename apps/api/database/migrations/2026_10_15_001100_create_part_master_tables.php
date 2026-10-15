<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 2a (doc 29 §1.2, doc 26 §2.2, doc 17, M2-D4, D-062): units, items, material specs, item UOM conversions,
 * die ↔ item links.
 *
 * - `uoms`: tenant-owned units with a class and a fixed number of decimals (doc 24 §3.2: kg 3 dp, pcs/sheet 0). Every
 *   tenant starts with KG, PCS and SHEET (trigger on tenant creation; existing tenants via `eora:tenants:sync`).
 *   Code, class and decimals never change once created (rounding of stored quantities depends on them).
 * - `items` (Part Master): tenant-defined upper-case codes (M2-D4), type, base UOM (fixed), lot control, inspection
 *   and incoming-release flags (D-062), drawing, status ACTIVE / INACTIVE / OBSOLETE. Deactivate, never delete.
 *   Prefix (B-tree) + trigram (GIN) indexes for search (D-B2-09).
 * - `material_specs`: at most one per item (grade, thickness, width, density) — the measured material properties the
 *   conversion formulas of doc 02 read.
 * - `uom_conversions`: item-specific and versioned: one factor per unordered unit pair per day (inclusive effective
 *   ranges, exclusion constraint D-B2-08). A new version closes the open one the day before; nothing else changes
 *   (trigger). Released work orders copy the factor they use (B5), so a revision never rewrites history.
 * - `die_items`: which parts a die makes and how many per stroke (family dies, alternative dies).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE uoms (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9_]{0,9}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
              uom_class text NOT NULL CHECK (uom_class IN ('MASS', 'COUNT', 'LENGTH', 'AREA', 'VOLUME')),
              decimals smallint NOT NULL CHECK (decimals BETWEEN 0 AND 6),
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

            CREATE TABLE items (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._/-]{0,39}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              item_type text NOT NULL CHECK (item_type IN ('RAW', 'SEMI_FINISHED', 'FINISHED', 'SCRAP', 'CONSUMABLE')),
              base_uom_id uuid NOT NULL,
              lot_controlled boolean NOT NULL DEFAULT true,
              inspection_required boolean NOT NULL DEFAULT false,
              incoming_release_required boolean NOT NULL DEFAULT false,
              drawing_no text CHECK (length(drawing_no) BETWEEN 1 AND 60),
              drawing_rev text CHECK (length(drawing_rev) BETWEEN 1 AND 20),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'OBSOLETE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code),
              FOREIGN KEY (tenant_id, base_uom_id) REFERENCES uoms (tenant_id, id),
              CHECK (drawing_rev IS NULL OR drawing_no IS NOT NULL)
            );
            CREATE INDEX items_code_prefix_idx ON items (tenant_id, code text_pattern_ops);
            CREATE INDEX items_search_trgm_idx ON items USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);
            CREATE INDEX items_type_idx ON items (tenant_id, item_type, status);

            CREATE TABLE material_specs (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              grade text CHECK (length(grade) BETWEEN 1 AND 60),
              thickness_mm numeric(10,3) CHECK (thickness_mm > 0),
              width_mm numeric(10,3) CHECK (width_mm > 0),
              density_g_cm3 numeric(8,4) CHECK (density_g_cm3 > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, item_id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );

            CREATE TABLE uom_conversions (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              from_uom_id uuid NOT NULL,
              to_uom_id uuid NOT NULL,
              factor numeric(20,10) NOT NULL CHECK (factor > 0),
              effective_from date NOT NULL,
              effective_to date,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, from_uom_id) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, to_uom_id) REFERENCES uoms (tenant_id, id),
              CHECK (from_uom_id <> to_uom_id),
              CHECK (effective_to IS NULL OR effective_to >= effective_from),
              -- one factor per unordered unit pair and item on every day (kg→pcs and pcs→kg can never disagree)
              EXCLUDE USING gist (
                tenant_id WITH =, item_id WITH =, LEAST(from_uom_id, to_uom_id) WITH =, GREATEST(from_uom_id, to_uom_id) WITH =,
                daterange(effective_from, effective_to, '[]') WITH &&
              )
            );
            CREATE INDEX uom_conversions_item_idx ON uom_conversions (tenant_id, item_id);

            -- a version changes only by being closed once: effective_to from NULL to a day on or after its start
            CREATE OR REPLACE FUNCTION eora_guard_uom_conversion() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF OLD.effective_to IS NOT NULL OR NEW.effective_to IS NULL
                 OR NEW.item_id <> OLD.item_id OR NEW.from_uom_id <> OLD.from_uom_id OR NEW.to_uom_id <> OLD.to_uom_id
                 OR NEW.factor <> OLD.factor OR NEW.effective_from <> OLD.effective_from THEN
                RAISE EXCEPTION 'a UOM conversion version changes only by being closed once' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_uom_conversion() FROM PUBLIC;
            CREATE TRIGGER guard_version BEFORE UPDATE ON uom_conversions FOR EACH ROW EXECUTE FUNCTION eora_guard_uom_conversion();

            CREATE TABLE die_items (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              die_id uuid NOT NULL,
              item_id uuid NOT NULL,
              parts_per_stroke integer NOT NULL DEFAULT 1 CHECK (parts_per_stroke BETWEEN 1 AND 1000),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, die_id, item_id),
              FOREIGN KEY (tenant_id, die_id) REFERENCES dies (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );
            CREATE INDEX die_items_item_idx ON die_items (tenant_id, item_id);

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON uoms FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON items FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON material_specs FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON uom_conversions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON die_items FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- the starter units of every tenant, written in the new tenant's own signed context (invoker: RLS applies)
            CREATE OR REPLACE FUNCTION eora_tenant_default_uoms() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              INSERT INTO public.uoms (tenant_id, code, name, uom_class, decimals)
              VALUES (NEW.id, 'KG', 'Kilogram', 'MASS', 3), (NEW.id, 'PCS', 'Pieces', 'COUNT', 0), (NEW.id, 'SHEET', 'Sheet', 'COUNT', 0)
              ON CONFLICT (tenant_id, code) DO NOTHING;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_tenant_default_uoms() FROM PUBLIC;
            CREATE TRIGGER default_uoms AFTER INSERT ON tenants FOR EACH ROW EXECUTE FUNCTION eora_tenant_default_uoms();

            ALTER TABLE uoms ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON uoms TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON uoms TO eora_app;
            GRANT UPDATE (name, status) ON uoms TO eora_app;
            ALTER TABLE items ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON items TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON items TO eora_app;
            GRANT UPDATE (name, item_type, lot_controlled, inspection_required, incoming_release_required, drawing_no, drawing_rev, status) ON items TO eora_app;
            ALTER TABLE material_specs ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON material_specs TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON material_specs TO eora_app;
            GRANT UPDATE (grade, thickness_mm, width_mm, density_g_cm3) ON material_specs TO eora_app;
            ALTER TABLE uom_conversions ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON uom_conversions TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON uom_conversions TO eora_app;
            GRANT UPDATE (effective_to) ON uom_conversions TO eora_app;
            ALTER TABLE die_items ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON die_items TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON die_items TO eora_app;
            GRANT UPDATE (parts_per_stroke, status) ON die_items TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER default_uoms ON tenants;
            DROP FUNCTION eora_tenant_default_uoms();
            DROP TABLE die_items;
            DROP TABLE uom_conversions;
            DROP FUNCTION eora_guard_uom_conversion();
            DROP TABLE material_specs;
            DROP TABLE items;
            DROP TABLE uoms;
            SQL);
    }
};

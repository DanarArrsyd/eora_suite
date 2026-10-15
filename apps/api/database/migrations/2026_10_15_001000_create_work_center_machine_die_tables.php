<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 1b (doc 29 §1.1, doc 26 §2.1, doc 17, ADR-0002 §3, ADR-0003 §4, D-046 G-MPS-7): work centers, machines, dies.
 *
 * - `work_centers`: tenant-defined `work_center_type` code (e.g. PRESS, WELDING; process types point at it later);
 *   `available_hours_per_shift` NULL = capacity NOT CONFIGURED (capacity stays warning-only, D-046). Each work center
 *   gets exactly one `MAT_AT_PROD` location (Material at Production, never "WIP"), created by a trigger with code
 *   `MAP-<work center code>`; the location's `work_center_id` + a partial unique index make the pair one-to-one.
 * - `machines` belong to exactly one work center of the same plant at a time (composite FK); moving one is an
 *   audited update. `stroke_counter` marks machines that report stroke readings (ADR-0003 §4).
 * - `dies` (die / jig / fixture) are plant-scoped tooling with cavities and stroke-life counters; codes unique per
 *   tenant (doc 26 §2.1); a plant change is an ordinary audited update, no tooling-transfer module.
 * - Codes immutable (column grants); forced RLS bound to the signed context; no DELETE for runtime roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_centers (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,15}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              work_center_type text NOT NULL CHECK (work_center_type ~ '^[A-Z][A-Z0-9_]{1,31}$'),
              available_hours_per_shift numeric(6,2) CHECK (available_hours_per_shift > 0 AND available_hours_per_shift <= 24),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, plant_id, code),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );

            ALTER TABLE locations ADD COLUMN work_center_id uuid;
            ALTER TABLE locations ADD CONSTRAINT locations_work_center_fk
              FOREIGN KEY (tenant_id, plant_id, work_center_id) REFERENCES work_centers (tenant_id, plant_id, id);
            ALTER TABLE locations ADD CONSTRAINT locations_mat_at_prod_wc CHECK ((location_type = 'MAT_AT_PROD') = (work_center_id IS NOT NULL));
            CREATE UNIQUE INDEX locations_mat_at_prod_uq ON locations (tenant_id, work_center_id) WHERE work_center_id IS NOT NULL;

            CREATE TABLE machines (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              work_center_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              stroke_counter boolean NOT NULL DEFAULT false,
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, code),
              FOREIGN KEY (tenant_id, plant_id, work_center_id) REFERENCES work_centers (tenant_id, plant_id, id)
            );
            CREATE INDEX machines_work_center_idx ON machines (tenant_id, work_center_id);

            CREATE TABLE dies (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              die_type text NOT NULL CHECK (die_type IN ('DIE', 'JIG', 'FIXTURE')),
              cavities integer NOT NULL DEFAULT 1 CHECK (cavities BETWEEN 1 AND 1000),
              stroke_life bigint CHECK (stroke_life > 0),
              current_strokes bigint NOT NULL DEFAULT 0 CHECK (current_strokes >= 0),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'MAINTENANCE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, code),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );
            CREATE INDEX dies_plant_idx ON dies (tenant_id, plant_id);

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON work_centers FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON machines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON dies FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- the work center's Material at Production location, written with it (invoker: RLS applies)
            CREATE OR REPLACE FUNCTION eora_work_center_location() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              INSERT INTO public.locations (tenant_id, plant_id, work_center_id, code, name, location_type)
              VALUES (NEW.tenant_id, NEW.plant_id, NEW.id, 'MAP-' || NEW.code, pg_catalog.left('Material at Production — ' || NEW.name, 200), 'MAT_AT_PROD');
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_work_center_location() FROM PUBLIC;
            CREATE TRIGGER mat_at_prod_location AFTER INSERT ON work_centers FOR EACH ROW EXECUTE FUNCTION eora_work_center_location();

            ALTER TABLE work_centers ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON work_centers TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON work_centers TO eora_app;
            GRANT UPDATE (name, work_center_type, available_hours_per_shift, status) ON work_centers TO eora_app;
            ALTER TABLE machines ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON machines TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON machines TO eora_app;
            GRANT UPDATE (name, work_center_id, stroke_counter, status) ON machines TO eora_app;
            ALTER TABLE dies ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON dies TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON dies TO eora_app;
            GRANT UPDATE (plant_id, name, die_type, cavities, stroke_life, current_strokes, status) ON dies TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE dies;
            DROP TABLE machines;
            DROP TRIGGER mat_at_prod_location ON work_centers;
            DROP FUNCTION eora_work_center_location();
            -- MAT_AT_PROD rows sit behind forced RLS (not the owner's to delete); after a rollback they remain unlinked
            DROP INDEX locations_mat_at_prod_uq;
            ALTER TABLE locations DROP CONSTRAINT locations_mat_at_prod_wc;
            ALTER TABLE locations DROP CONSTRAINT locations_work_center_fk;
            ALTER TABLE locations DROP COLUMN work_center_id;
            DROP TABLE work_centers;
            SQL);
    }
};

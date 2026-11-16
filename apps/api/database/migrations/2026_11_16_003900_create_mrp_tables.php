<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M5 step 4 (doc 34 §1.4, D-124, D-046 G-MPS-5, D-059, BR-PLN-06): basic MRP from an approved MPS.
 *
 * - `mrp_runs` (`MRP-<plant>-<year>-NNNN`): a read-only snapshot a planner runs from the plant's APPROVED MPS
 *   (`planning.mrp.create`); read with `planning.mrp.view`. Rows of a run are written in the transaction that created it
 *   only (created_at = now()), never changed or deleted afterwards (no UPDATE / DELETE grant).
 * - Per item of a run (`mrp_items`: MAKE = finished / semi-finished, BUY = raw material / consumable, low-level code,
 *   on hand, what released work orders still need of it, safety stock, lead time): `mrp_requirements` (dependent demand
 *   from a parent order's BOM explosion, on the parent's start date), `mrp_supplies` (supplier inbound on its expected
 *   date, remaining output of released work orders on their due date), `mrp_orders` (MAKE: planned work orders, FG from
 *   the MPS and semi-finished from the explosion; BUY: the material shortage list with need and order-by date) and
 *   `mrp_pegs` (which requirement an order covers; NULL = safety stock).
 *
 * Existing tenants receive the template permissions through `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['planning.mrp.view', 'view', false, ['PPC', 'CSA', 'MAT', 'PRL', 'INV', 'AUD']],
        ['planning.mrp.create', 'create', false, ['PPC']],
    ];

    private const array CHILDREN = ['mrp_items', 'mrp_requirements', 'mrp_supplies', 'mrp_orders', 'mrp_pegs'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$code, $action, $sensitive, $templates]) {
            DB::insert("insert into permissions (code, module, action, sensitive) values (?, 'planning', ?, ?) on conflict (code) do nothing", [$code, $action, $sensitive]);
            foreach ($templates as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE mrp_runs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              mps_version_id uuid NOT NULL,
              run_no text NOT NULL CHECK (run_no ~ '^MRP-[A-Z0-9_-]+-[0-9]{4}-[0-9]{4,}$'),
              plan_date date NOT NULL,
              horizon_start date NOT NULL CHECK (extract(isodow FROM horizon_start) = 1),
              horizon_weeks integer NOT NULL CHECK (horizon_weeks BETWEEN 1 AND 52),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, run_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, mps_version_id) REFERENCES mps_versions (tenant_id, id)
            );
            CREATE INDEX mrp_runs_mps_idx ON mrp_runs (tenant_id, mps_version_id, created_at DESC);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON mrp_runs FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE mrp_runs ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON mrp_runs TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON mrp_runs TO eora_app;

            CREATE TABLE mrp_items (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mrp_run_id uuid NOT NULL,
              item_id uuid NOT NULL,
              uom_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('MAKE', 'BUY')),
              level integer NOT NULL CHECK (level BETWEEN 0 AND 20),
              on_hand numeric(20, 6) NOT NULL CHECK (on_hand >= 0),
              committed numeric(20, 6) NOT NULL CHECK (committed >= 0),
              safety_stock numeric(20, 6) CHECK (safety_stock >= 0),
              lead_time_days integer CHECK (lead_time_days >= 0),
              -- UOM_CONVERSION_MISSING: a released work order's requirement could not be converted (not netted)
              warnings text[] NOT NULL DEFAULT '{}',
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mrp_run_id, item_id),
              UNIQUE (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, mrp_run_id) REFERENCES mrp_runs (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id)
            );

            -- planned work orders (MAKE) and the material shortage list (BUY), lot-for-lot per week
            CREATE TABLE mrp_orders (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mrp_run_id uuid NOT NULL,
              mrp_item_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('MAKE', 'BUY')),
              source text NOT NULL CHECK (source IN ('MPS', 'MRP')),
              mps_planned_order_id uuid,
              week_start date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              due_date date NOT NULL,
              start_date date NOT NULL CHECK (start_date <= due_date),
              bom_version_id uuid,
              -- LATE_START, LEAD_TIME_NOT_CONFIGURED, NO_BOM, UOM_CONVERSION_MISSING
              warnings text[] NOT NULL DEFAULT '{}',
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, mrp_item_id, mrp_run_id) REFERENCES mrp_items (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, mps_planned_order_id) REFERENCES mps_planned_orders (tenant_id, id),
              FOREIGN KEY (tenant_id, bom_version_id) REFERENCES bom_versions (tenant_id, id),
              CHECK ((source = 'MPS') = (mps_planned_order_id IS NOT NULL)),
              CHECK (kind = 'MAKE' OR (source = 'MRP' AND bom_version_id IS NULL))
            );
            CREATE INDEX mrp_orders_run_idx ON mrp_orders (tenant_id, mrp_run_id, kind, due_date);

            -- dependent demand: a parent order's BOM line, due on the parent's start date, in the component's base unit
            CREATE TABLE mrp_requirements (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mrp_run_id uuid NOT NULL,
              mrp_item_id uuid NOT NULL,
              parent_order_id uuid NOT NULL,
              bom_line_id uuid NOT NULL,
              due_date date NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, mrp_item_id, mrp_run_id) REFERENCES mrp_items (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, parent_order_id, mrp_run_id) REFERENCES mrp_orders (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, bom_line_id) REFERENCES bom_lines (tenant_id, id)
            );

            -- projected supply netted on its day (class B): supplier inbound, remaining output of released work orders
            CREATE TABLE mrp_supplies (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mrp_run_id uuid NOT NULL,
              mrp_item_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('INBOUND', 'WORK_ORDER')),
              inbound_line_id uuid,
              work_order_id uuid,
              supply_date date NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, mrp_item_id, mrp_run_id) REFERENCES mrp_items (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, inbound_line_id) REFERENCES supplier_inbound_lines (tenant_id, id),
              FOREIGN KEY (tenant_id, work_order_id) REFERENCES work_orders (tenant_id, id),
              CHECK ((kind = 'INBOUND') = (inbound_line_id IS NOT NULL) AND (kind = 'WORK_ORDER') = (work_order_id IS NOT NULL))
            );

            CREATE TABLE mrp_pegs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mrp_run_id uuid NOT NULL,
              order_id uuid NOT NULL,
              requirement_id uuid,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE NULLS NOT DISTINCT (tenant_id, order_id, requirement_id),
              FOREIGN KEY (tenant_id, order_id, mrp_run_id) REFERENCES mrp_orders (tenant_id, id, mrp_run_id),
              FOREIGN KEY (tenant_id, requirement_id, mrp_run_id) REFERENCES mrp_requirements (tenant_id, id, mrp_run_id)
            );

            -- a run is made by a planner of its plant from its approved MPS; its rows only in that same transaction
            CREATE OR REPLACE FUNCTION eora_guard_mrp() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              r record;
            BEGIN
              IF TG_TABLE_NAME = 'mrp_runs' THEN
                IF NOT public.eora_actor_may('planning.mrp.create', NEW.plant_id) THEN
                  RAISE EXCEPTION 'MRP is run by a planner of the plant' USING ERRCODE = '42501';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.mps_versions m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.mps_version_id
                                 AND m.plant_id = NEW.plant_id AND m.status = 'APPROVED') THEN
                  RAISE EXCEPTION 'MRP runs from the plant''s approved MPS' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              SELECT x.plant_id, x.created_at INTO r FROM public.mrp_runs x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.mrp_run_id;
              IF r.created_at IS DISTINCT FROM now() OR NOT public.eora_actor_may('planning.mrp.create', r.plant_id) THEN
                RAISE EXCEPTION 'an MRP run is written once, in the transaction that runs it' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_mrp() FROM PUBLIC;
            CREATE TRIGGER guard_mrp BEFORE INSERT ON mrp_runs FOR EACH ROW EXECUTE FUNCTION eora_guard_mrp();
            SQL);

        foreach (self::CHILDREN as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
                CREATE TRIGGER guard_mrp BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION eora_guard_mrp();
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table} TO eora_app
                  USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
                GRANT SELECT, INSERT ON {$table} TO eora_app;
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['mrp_pegs', 'mrp_requirements', 'mrp_supplies', 'mrp_orders', 'mrp_items', 'mrp_runs'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS eora_guard_mrp()');
        foreach (self::PERMISSIONS as [$code]) {
            DB::delete('delete from role_template_permissions where permission_code = ?', [$code]);
            try {
                DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [$code]));
            } catch (QueryException $e) {
                // still held by a tenant role: the catalogue entry stays (as migration 002300)
                if ($e->getCode() !== '23503') {
                    throw $e;
                }
            }
        }
    }
};

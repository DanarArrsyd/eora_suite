<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5 step 5 (doc 34 §1.5, D-125, BR-PLN-07, BR-DEM-12, D-109): a planned work order of the latest MRP run of the plant's
 * approved MPS becomes a WO Draft.
 *
 * - `work_orders.mrp_order_id`: the planned order a WO was released from (reason `PLANNED`, never a planning exception);
 *   one live WO per planned order (a cancelled one frees it); written at insert only, by someone holding
 *   `planning.planned_order_release.post` in the plant, for a MAKE order of the same item and plant in the latest run of
 *   the plant's APPROVED MPS. Its item and source never change.
 * - Semi-finished work orders are allowed (Option M, D-059): the draft check accepts finished and semi-finished items.
 */
return new class extends Migration
{
    /** [function, original text, replacement] patched in place (asserted), restored by down(). */
    private const array PATCHES = [
        ['eora_check_work_order_draft(work_orders)',
            "AND i.item_type = 'FINISHED' AND i.status = 'ACTIVE'",
            "AND i.item_type IN ('FINISHED', 'SEMI_FINISHED') AND i.status = 'ACTIVE'", ],
        ['eora_check_work_order_draft(work_orders)',
            "'a work order makes an active finished item, counted in its base unit and decimals'",
            "'a work order makes an active finished or semi-finished item, counted in its base unit and decimals'", ],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- what work orders from the plan, still Draft / Waiting for release, need of an item (dated demand in the run)
            ALTER TABLE mrp_items ADD COLUMN wo_need numeric(20, 6) NOT NULL DEFAULT 0 CHECK (wo_need >= 0);
            ALTER TABLE work_orders ADD COLUMN mrp_order_id uuid;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_mrp_order_fk FOREIGN KEY (tenant_id, mrp_order_id) REFERENCES mrp_orders (tenant_id, id);
            ALTER TABLE work_orders DROP CONSTRAINT work_orders_reason_code_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_reason_code_check
              CHECK (reason_code IN ('URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER', 'PLANNED'));
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_planned_check CHECK ((reason_code = 'PLANNED') = (mrp_order_id IS NOT NULL));
            -- one live work order per planned order
            CREATE UNIQUE INDEX work_orders_mrp_order_uq ON work_orders (tenant_id, mrp_order_id) WHERE mrp_order_id IS NOT NULL AND status <> 'CANCELLED';

            -- a work order from the plan: released by a planner of the plant from the latest run of its approved MPS, for that
            -- planned order's item; it keeps its item and source
            CREATE OR REPLACE FUNCTION eora_guard_work_order_plan() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              o record;
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                IF (NEW.mrp_order_id, NEW.reason_code = 'PLANNED') IS DISTINCT FROM (OLD.mrp_order_id, OLD.reason_code = 'PLANNED')
                   OR (OLD.mrp_order_id IS NOT NULL AND NEW.item_id IS DISTINCT FROM OLD.item_id) THEN
                  RAISE EXCEPTION 'a work order from the plan keeps its item and source' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.mrp_order_id IS NULL THEN
                RETURN NEW;
              END IF;
              SELECT mo.kind, mo.mps_planned_order_id, mi.item_id, r.plant_id, r.id AS run_id, r.mps_version_id, m.status AS mps_status INTO o
                FROM public.mrp_orders mo
                JOIN public.mrp_items mi ON mi.tenant_id = mo.tenant_id AND mi.id = mo.mrp_item_id
                JOIN public.mrp_runs r ON r.tenant_id = mo.tenant_id AND r.id = mo.mrp_run_id
                JOIN public.mps_versions m ON m.tenant_id = r.tenant_id AND m.id = r.mps_version_id
               WHERE mo.tenant_id = NEW.tenant_id AND mo.id = NEW.mrp_order_id;
              IF o.kind IS DISTINCT FROM 'MAKE' OR o.item_id IS DISTINCT FROM NEW.item_id OR o.plant_id IS DISTINCT FROM NEW.plant_id THEN
                RAISE EXCEPTION 'a work order from the plan makes its planned order''s item at its plant' USING ERRCODE = '23514';
              END IF;
              IF o.mps_status IS DISTINCT FROM 'APPROVED'
                 OR EXISTS (SELECT 1 FROM public.mrp_runs l WHERE l.tenant_id = NEW.tenant_id AND l.mps_version_id = o.mps_version_id
                              AND (l.created_at, l.id) > (SELECT x.created_at, x.id FROM public.mrp_runs x WHERE x.tenant_id = NEW.tenant_id AND x.id = o.run_id)) THEN
                RAISE EXCEPTION 'planned orders are released from the latest MRP run of the approved MPS' USING ERRCODE = '23514';
              END IF;
              -- an MPS planned order appears in every MRP run of its MPS: it is released once, whichever run (its row is
              -- locked so two releases from different runs serialize)
              IF o.mps_planned_order_id IS NOT NULL THEN
                PERFORM 1 FROM public.mps_planned_orders p WHERE p.tenant_id = NEW.tenant_id AND p.id = o.mps_planned_order_id FOR UPDATE;
              END IF;
              IF o.mps_planned_order_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM public.work_orders w JOIN public.mrp_orders x ON x.tenant_id = w.tenant_id AND x.id = w.mrp_order_id
                    WHERE w.tenant_id = NEW.tenant_id AND w.status <> 'CANCELLED' AND x.mps_planned_order_id = o.mps_planned_order_id) THEN
                RAISE EXCEPTION 'this MPS planned order already has a work order' USING ERRCODE = '23505';
              END IF;
              IF NOT public.eora_actor_may('planning.planned_order_release.post', NEW.plant_id) THEN
                RAISE EXCEPTION 'a planned order is released by a planner of the plant' USING ERRCODE = '42501';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_work_order_plan() FROM PUBLIC;
            CREATE TRIGGER guard_plan BEFORE INSERT OR UPDATE ON work_orders FOR EACH ROW EXECUTE FUNCTION eora_guard_work_order_plan();
            SQL);
        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS guard_plan ON work_orders;
            DROP FUNCTION IF EXISTS eora_guard_work_order_plan();
            DROP INDEX IF EXISTS work_orders_mrp_order_uq;
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_planned_check;
            ALTER TABLE work_orders DROP CONSTRAINT work_orders_reason_code_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_reason_code_check
              CHECK (reason_code IN ('URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER'));
            ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_mrp_order_fk;
            ALTER TABLE work_orders DROP COLUMN IF EXISTS mrp_order_id;
            ALTER TABLE mrp_items DROP COLUMN IF EXISTS wo_need;
            SQL);
    }

    /** Replaces each original text in its function's definition (or back), asserting the text is there exactly once. */
    private function patch(bool $back): void
    {
        $definitions = [];
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definitions[$function] ??= (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definitions[$function], $find) !== 1) {
                throw new RuntimeException("{$function}: the text to patch is not there exactly once.");
            }
            $definitions[$function] = str_replace($find, $replace, $definitions[$function]);
        }
        foreach ($definitions as $definition) {
            // the definition comes from the database itself (pg_get_functiondef), changed only by the constant texts above
            DB::connection()->getPdo()->exec($definition);
        }
    }
};

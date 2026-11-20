<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generate SPK (doc 38 §12, D-139), slice 2. Generate SPK for a production date creates the work orders the plan needs
 * with reason `SPK` and releases them at once by the generating planner — an exception to the second-person release of
 * D-109 for those work orders only, recorded in `spk_generated_orders` and audited. A WO with reason `SPK` never exists
 * without that record (checked at commit) and never changes reason. Plants may set a limit of proposed overtime hours
 * per machine and day (empty = no limit; beyond it the proposal is flagged, not blocked).
 */
return new class extends Migration
{
    /** WO guard: the release of a generated WO by its generating planner (G-2), next to the second-person rule. */
    private const array PATCHES = [
        ['eora_guard_work_order()',
            "IF NEW.released_by IS DISTINCT FROM a OR a = OLD.submitted_by THEN
        RAISE EXCEPTION 'a work order is released by a second person' USING ERRCODE = '42501';
      END IF;
      IF NOT public.eora_actor_may('planning.wo.approve', NEW.plant_id) THEN
        RAISE EXCEPTION 'a work order is released by an approver of its plant' USING ERRCODE = '42501';
      END IF;",
            "IF NEW.reason_code = 'SPK' THEN
        -- D-139 G-2: a work order generated for an SPK is released by the planner who generated it
        IF NEW.released_by IS DISTINCT FROM a OR a IS DISTINCT FROM OLD.submitted_by
           OR NOT public.eora_actor_may('production.spk.create', NEW.plant_id)
           OR NOT EXISTS (SELECT 1 FROM public.spk_generated_orders g WHERE g.tenant_id = NEW.tenant_id AND g.work_order_id = NEW.id AND g.generated_by = a) THEN
          RAISE EXCEPTION 'a work order generated for an SPK is released by the planner who generated it' USING ERRCODE = '42501';
        END IF;
      ELSE
      IF NEW.released_by IS DISTINCT FROM a OR a = OLD.submitted_by THEN
        RAISE EXCEPTION 'a work order is released by a second person' USING ERRCODE = '42501';
      END IF;
      IF NOT public.eora_actor_may('planning.wo.approve', NEW.plant_id) THEN
        RAISE EXCEPTION 'a work order is released by an approver of its plant' USING ERRCODE = '42501';
      END IF;
      END IF;", ],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_orders DROP CONSTRAINT work_orders_reason_code_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_reason_code_check
              CHECK (reason_code IN ('URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER', 'PLANNED', 'SPK'));

            ALTER TABLE plants ADD COLUMN spk_ot_limit_hours numeric(5,2) CHECK (spk_ot_limit_hours > 0 AND spk_ot_limit_hours <= 24);

            CREATE TABLE spk_generated_orders (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              work_order_id uuid NOT NULL,
              production_date date NOT NULL,
              generated_by uuid NOT NULL,
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, work_order_id),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants(tenant_id, id),
              FOREIGN KEY (tenant_id, work_order_id) REFERENCES work_orders(tenant_id, id)
            );
            CREATE INDEX spk_generated_orders_date_idx ON spk_generated_orders (tenant_id, plant_id, production_date);
            ALTER TABLE spk_generated_orders ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON spk_generated_orders TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON spk_generated_orders TO eora_app;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON spk_generated_orders FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- the generated record: written once, by a planner of the plant, for a new SPK work order of that plant, as the signed actor
            CREATE OR REPLACE FUNCTION eora_guard_spk_generated_order() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE w record;
            BEGIN
              SELECT x.plant_id, x.status, x.reason_code INTO w FROM public.work_orders x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.work_order_id;
              IF w.plant_id IS DISTINCT FROM NEW.plant_id OR w.status IS DISTINCT FROM 'DRAFT' OR w.reason_code IS DISTINCT FROM 'SPK'
                 OR NEW.generated_by IS DISTINCT FROM (SELECT c.actor FROM ctx_api.context() c)
                 OR NOT public.eora_actor_may('production.spk.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'an SPK work order is recorded by the planner who generates it, for a new draft of its plant' USING ERRCODE = '42501';
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_spk_generated_order() FROM PUBLIC;
            CREATE TRIGGER guard_generated BEFORE INSERT ON spk_generated_orders FOR EACH ROW EXECUTE FUNCTION eora_guard_spk_generated_order();

            -- reason SPK: never given or taken by an edit, and never without its generated record at commit
            CREATE OR REPLACE FUNCTION eora_guard_wo_spk_reason() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF (OLD.reason_code = 'SPK') IS DISTINCT FROM (NEW.reason_code = 'SPK') THEN
                RAISE EXCEPTION 'a work order generated for an SPK keeps its reason' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_wo_spk_reason() FROM PUBLIC;
            CREATE TRIGGER guard_spk_reason BEFORE UPDATE OF reason_code ON work_orders FOR EACH ROW EXECUTE FUNCTION eora_guard_wo_spk_reason();

            CREATE OR REPLACE FUNCTION eora_check_wo_spk_generated() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.spk_generated_orders g WHERE g.tenant_id = NEW.tenant_id AND g.work_order_id = NEW.id) THEN
                RAISE EXCEPTION 'a work order with reason SPK is made by Generate SPK' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_check_wo_spk_generated() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_spk_generated AFTER INSERT ON work_orders DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.reason_code = 'SPK') EXECUTE FUNCTION eora_check_wo_spk_generated();
            SQL);
        $this->patch(false);
    }

    public function down(): void
    {
        // work orders Generate SPK made keep their reason and record: a rollback would leave them unexplained
        if (DB::selectOne("select 1 as made from work_orders where reason_code = 'SPK' limit 1") !== null) {
            throw new RuntimeException('Work orders made by Generate SPK exist; this migration cannot be rolled back without losing their history.');
        }
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER check_spk_generated ON work_orders;
            DROP TRIGGER guard_spk_reason ON work_orders;
            DROP FUNCTION eora_check_wo_spk_generated();
            DROP FUNCTION eora_guard_wo_spk_reason();
            DROP TABLE spk_generated_orders;
            DROP FUNCTION eora_guard_spk_generated_order();
            ALTER TABLE plants DROP COLUMN spk_ot_limit_hours;
            ALTER TABLE work_orders DROP CONSTRAINT work_orders_reason_code_check;
            ALTER TABLE work_orders ADD CONSTRAINT work_orders_reason_code_check
              CHECK (reason_code IN ('URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER', 'PLANNED'));
            SQL);
    }

    private function patch(bool $back): void
    {
        $definitions = [];
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definitions[$function] ??= (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definitions[$function], $find) !== 1) {
                throw new RuntimeException("Could not locate the release rule in {$function}.");
            }
            $definitions[$function] = str_replace($find, $replace, $definitions[$function]);
        }
        foreach ($definitions as $definition) {
            DB::connection()->getPdo()->exec($definition);
        }
    }
};

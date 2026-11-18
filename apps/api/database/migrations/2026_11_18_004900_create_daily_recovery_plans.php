<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array RIGHTS = [
        ['planning.recovery.view', 'view', ['PPC', 'CSA', 'AUD']],
        ['planning.recovery.create', 'create', ['PPC']],
    ];

    public function up(): void
    {
        foreach (self::RIGHTS as [$code, $action, $roles]) {
            DB::insert('insert into permissions (code, module, action, sensitive) values (?, ?, ?, false) on conflict do nothing', [$code, 'planning', $action]);
            foreach ($roles as $role) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$role, $code]);
            }
        }
        DB::unprepared(<<<'SQL'
            CREATE TABLE daily_recovery_plans (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              customer_id uuid NOT NULL,
              item_id uuid NOT NULL,
              target_date date NOT NULL,
              revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
              minimum_qty bigint NOT NULL DEFAULT 500 CHECK (minimum_qty BETWEEN 0 AND 1000000000),
              batch_qty bigint CHECK (batch_qty BETWEEN 1 AND 1000000000),
              buffer_qty bigint NOT NULL DEFAULT 0 CHECK (buffer_qty BETWEEN 0 AND 1000000000),
              shift_1_qty bigint NOT NULL DEFAULT 0 CHECK (shift_1_qty BETWEEN 0 AND 1000000000),
              shift_2_qty bigint NOT NULL DEFAULT 0 CHECK (shift_2_qty BETWEEN 0 AND 1000000000),
              shift_3_qty bigint NOT NULL DEFAULT 0 CHECK (shift_3_qty BETWEEN 0 AND 1000000000),
              reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 500),
              input_fingerprint text CHECK (input_fingerprint ~ '^[0-9a-f]{64}$'),
              input_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (pg_column_size(input_snapshot) <= 65536),
              formula_code text NOT NULL DEFAULT 'DAILY_RECOVERY_V1' CHECK (formula_code = 'DAILY_RECOVERY_V1'),
              work_order_id uuid,
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              UNIQUE(tenant_id,id),
              UNIQUE(tenant_id,plant_id,customer_id,item_id,target_date,revision),
              UNIQUE(tenant_id,work_order_id),
              FOREIGN KEY(tenant_id,plant_id) REFERENCES plants(tenant_id,id),
              FOREIGN KEY(tenant_id,customer_id) REFERENCES customers(tenant_id,id),
              FOREIGN KEY(tenant_id,item_id) REFERENCES items(tenant_id,id),
              FOREIGN KEY(tenant_id,plant_id,work_order_id) REFERENCES work_orders(tenant_id,plant_id,id)
            );
            CREATE UNIQUE INDEX daily_recovery_one_draft ON daily_recovery_plans(tenant_id,plant_id,customer_id,item_id,target_date) WHERE work_order_id IS NULL;
            CREATE INDEX daily_recovery_plans_date_idx ON daily_recovery_plans(tenant_id,plant_id,target_date);
            ALTER TABLE daily_recovery_plans ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON daily_recovery_plans TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON daily_recovery_plans TO eora_app;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON daily_recovery_plans FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE OR REPLACE FUNCTION eora_guard_daily_recovery_plan() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT public.eora_actor_may('planning.recovery.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'daily recovery is changed by a planner of the plant' USING ERRCODE = '42501';
              END IF;
              IF NOT EXISTS (SELECT 1 FROM public.items i JOIN public.uoms u ON u.id = i.base_uom_id
                             WHERE i.id = NEW.item_id AND i.item_type = 'FINISHED' AND u.code = 'PCS') THEN
                RAISE EXCEPTION 'daily recovery plans finished PCS items' USING ERRCODE = '23514';
              END IF;
              IF TG_OP = 'UPDATE' THEN
                IF (NEW.tenant_id,NEW.plant_id,NEW.customer_id,NEW.item_id,NEW.target_date,NEW.revision)
                   IS DISTINCT FROM (OLD.tenant_id,OLD.plant_id,OLD.customer_id,OLD.item_id,OLD.target_date,OLD.revision) THEN
                  RAISE EXCEPTION 'daily plan identity is fixed' USING ERRCODE = '23514';
                END IF;
                IF OLD.work_order_id IS NOT NULL THEN
                  RAISE EXCEPTION 'a daily plan already linked to a work order is history' USING ERRCODE = '23514';
                END IF;
              END IF;
              IF NEW.work_order_id IS NOT NULL AND (NOT public.eora_actor_may('planning.wo.create', NEW.plant_id)
                OR NOT EXISTS (SELECT 1 FROM public.work_orders w WHERE w.id = NEW.work_order_id
                               AND w.tenant_id = NEW.tenant_id AND w.plant_id = NEW.plant_id AND w.item_id = NEW.item_id
                               AND w.status = 'DRAFT' AND w.due_date = NEW.target_date
                               AND w.qty = NEW.shift_1_qty + NEW.shift_2_qty + NEW.shift_3_qty)) THEN
                RAISE EXCEPTION 'daily plan links its matching draft work order' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_daily_recovery_plan() FROM PUBLIC;
            CREATE TRIGGER guard_daily_recovery BEFORE INSERT OR UPDATE ON daily_recovery_plans
              FOR EACH ROW EXECUTE FUNCTION eora_guard_daily_recovery_plan();
            ALTER TABLE mps_supplies DROP CONSTRAINT mps_supplies_kind_check;
            ALTER TABLE mps_supplies ADD CONSTRAINT mps_supplies_kind_check CHECK (kind IN ('QC_RELEASED','WORK_ORDER','PENDING_QC'));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE mps_supplies DROP CONSTRAINT mps_supplies_kind_check;
            ALTER TABLE mps_supplies ADD CONSTRAINT mps_supplies_kind_check CHECK (kind IN ('QC_RELEASED','WORK_ORDER'));
            DROP TABLE daily_recovery_plans;
            DROP FUNCTION eora_guard_daily_recovery_plan();
            SQL);
        foreach (self::RIGHTS as [$code]) {
            DB::delete('delete from role_template_permissions where permission_code = ?', [$code]);
            try {
                DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [$code]));
            } catch (QueryException $e) {
                if ($e->getCode() !== '23503') {
                    throw $e;
                }
            }
        }
    }
};

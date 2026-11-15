<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5 step 3 (doc 34 §1.3, D-123, D-046, BR-PLN-04/05/07, doc 23 §4–§7): the master production schedule.
 *
 * - `mps_versions` per plant (`MPS-<plant>-<year>-NNNN`), 12 ISO weeks from the week of the plant's today (D-120):
 *   DRAFT → SUBMITTED → APPROVED → SUPERSEDED; SUBMITTED may go back to DRAFT with a reason, a DRAFT may be cancelled.
 *   A planner (`planning.mps.create`) calculates and adjusts it, submits it (`planning.mps.submit`); a CSA approves it
 *   (`planning.mps.approve`), never the one who calculated, submitted or adjusted it (SoD-07, also in SQL). One open
 *   (DRAFT/SUBMITTED) and one APPROVED version per plant. `mps_contributors` keeps everyone who calculated, submitted
 *   or adjusted a version for its whole life (SoD checks it, not only the latest names). `input_fingerprint` is the hash of what it was calculated
 *   from; the API refuses approval when the inputs changed since (D-123 (2)).
 * - What a calculation wrote, per FG item: `mps_items` (opening on hand, out-of-scope allocation, safety stock, Pending QC
 *   shown only), `mps_demands` (firm outstanding per schedule line and net forecast per forecast line, each on its day),
 *   `mps_supplies` (QC released, remaining WO output, dated), `mps_buckets` (per ISO week), `mps_planned_orders`
 *   (lot-for-lot per week, due on the earliest uncovered day; quantity and due date adjustable in a draft with a reason)
 *   and `mps_pegs` (which demand a planned order covers, BR-DEM-12), `mps_capacity` (load per work center and week).
 * - Rows of a calculation are written while their version is a DRAFT only, by a planner of its plant; afterwards history.
 *   A recalculation deletes them child first (no ON DELETE CASCADE: a cascade runs as the table owner, outside the
 *   signed context the guard reads).
 */
return new class extends Migration
{
    private const array CHILDREN = ['mps_items', 'mps_demands', 'mps_supplies', 'mps_buckets', 'mps_planned_orders', 'mps_pegs', 'mps_capacity'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE mps_versions (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              mps_no text NOT NULL CHECK (mps_no ~ '^MPS-[A-Z0-9_-]+-[0-9]{4}-[0-9]{4,}$'),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'SUPERSEDED', 'CANCELLED')),
              horizon_start date NOT NULL CHECK (extract(isodow FROM horizon_start) = 1),
              horizon_weeks integer NOT NULL CHECK (horizon_weeks BETWEEN 1 AND 52),
              plan_date date NOT NULL CHECK (plan_date BETWEEN horizon_start AND horizon_start + 6),
              input_fingerprint text NOT NULL CHECK (input_fingerprint ~ '^[0-9a-f]{64}$'),
              calculated_at timestamptz NOT NULL,
              calculated_by uuid NOT NULL,
              note text CHECK (length(note) BETWEEN 1 AND 500),
              submitted_at timestamptz,
              submitted_by uuid,
              return_reason text CHECK (length(return_reason) BETWEEN 3 AND 500 AND return_reason = btrim(return_reason)),
              approved_at timestamptz,
              approved_by uuid,
              superseded_at timestamptz,
              cancelled_at timestamptz,
              cancelled_by uuid,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              CHECK ((status = 'SUBMITTED') = (submitted_at IS NOT NULL AND submitted_by IS NOT NULL) OR status IN ('APPROVED', 'SUPERSEDED')),
              CHECK ((status IN ('APPROVED', 'SUPERSEDED')) = (approved_at IS NOT NULL AND approved_by IS NOT NULL)),
              CHECK ((status = 'SUPERSEDED') = (superseded_at IS NOT NULL)),
              CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)),
              -- SoD-07: the approver neither calculated nor submitted it
              CHECK (approved_by IS NULL OR (approved_by <> calculated_by AND approved_by <> submitted_by))
            );
            CREATE UNIQUE INDEX mps_versions_open_uq ON mps_versions (tenant_id, plant_id) WHERE status IN ('DRAFT', 'SUBMITTED');
            CREATE UNIQUE INDEX mps_versions_approved_uq ON mps_versions (tenant_id, plant_id) WHERE status = 'APPROVED';
            CREATE INDEX mps_versions_plant_idx ON mps_versions (tenant_id, plant_id, created_at DESC);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON mps_versions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE mps_versions ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON mps_versions TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON mps_versions TO eora_app;

            -- per FG item of a calculation: opening position and the item's planning parameters as used
            CREATE TABLE mps_items (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              item_id uuid NOT NULL,
              uom_id uuid NOT NULL,
              on_hand numeric(20, 6) NOT NULL CHECK (on_hand >= 0),
              out_of_scope_allocated numeric(20, 6) NOT NULL CHECK (out_of_scope_allocated >= 0),
              safety_stock numeric(20, 6) CHECK (safety_stock >= 0),
              pending_qc numeric(20, 6) NOT NULL CHECK (pending_qc >= 0),
              production_lead_time_days integer CHECK (production_lead_time_days >= 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_version_id, item_id),
              UNIQUE (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, mps_version_id) REFERENCES mps_versions (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id)
            );

            -- the demand netted, each on its day: firm outstanding of a schedule line or net forecast of a forecast line
            CREATE TABLE mps_demands (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              mps_item_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('FIRM', 'FORECAST')),
              schedule_line_id uuid,
              forecast_line_id uuid,
              due_date date NOT NULL,
              original_date date NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, mps_item_id, mps_version_id) REFERENCES mps_items (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, schedule_line_id) REFERENCES delivery_schedule_lines (tenant_id, id),
              FOREIGN KEY (tenant_id, forecast_line_id) REFERENCES forecast_lines (tenant_id, id),
              CHECK ((kind = 'FIRM') = (schedule_line_id IS NOT NULL) AND (kind = 'FORECAST') = (forecast_line_id IS NOT NULL)),
              CHECK (due_date >= original_date)
            );
            CREATE INDEX mps_demands_item_idx ON mps_demands (tenant_id, mps_item_id, due_date);

            -- projected supply netted on its day (class B, D-043): QC released FG not yet received, remaining WO output
            CREATE TABLE mps_supplies (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              mps_item_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('QC_RELEASED', 'WORK_ORDER')),
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
              FOREIGN KEY (tenant_id, mps_item_id, mps_version_id) REFERENCES mps_items (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, work_order_id) REFERENCES work_orders (tenant_id, id),
              CHECK ((kind = 'WORK_ORDER') = (work_order_id IS NOT NULL))
            );
            CREATE INDEX mps_supplies_item_idx ON mps_supplies (tenant_id, mps_item_id, supply_date);

            -- the projected available balance per ISO week (doc 23 §4 F-1): opening once, receipts and demand dated
            CREATE TABLE mps_buckets (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              mps_item_id uuid NOT NULL,
              week_start date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
              opening numeric(20, 6) NOT NULL,
              firm_demand numeric(20, 6) NOT NULL CHECK (firm_demand >= 0),
              forecast_demand numeric(20, 6) NOT NULL CHECK (forecast_demand >= 0),
              receipts numeric(20, 6) NOT NULL CHECK (receipts >= 0),
              planned numeric(20, 6) NOT NULL CHECK (planned >= 0),
              closing numeric(20, 6) NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_item_id, week_start),
              FOREIGN KEY (tenant_id, mps_item_id, mps_version_id) REFERENCES mps_items (tenant_id, id, mps_version_id)
            );

            -- lot-for-lot per week (G-MPS-4); calc_* is what the calculation proposed, qty / due_date what the plan says
            CREATE TABLE mps_planned_orders (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              mps_item_id uuid NOT NULL,
              week_start date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
              calc_qty numeric(20, 6) NOT NULL CHECK (calc_qty > 0),
              calc_due_date date NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              due_date date NOT NULL,
              start_date date NOT NULL CHECK (start_date <= due_date),
              -- LATE_START, LEAD_TIME_NOT_CONFIGURED, NO_ROUTING (capacity not loaded)
              warnings text[] NOT NULL DEFAULT '{}',
              adjust_reason text CHECK (length(adjust_reason) BETWEEN 3 AND 500 AND adjust_reason = btrim(adjust_reason)),
              adjusted_at timestamptz,
              adjusted_by uuid,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_item_id, week_start),
              UNIQUE (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, mps_item_id, mps_version_id) REFERENCES mps_items (tenant_id, id, mps_version_id),
              CHECK ((adjust_reason IS NULL) = (adjusted_at IS NULL) AND (adjusted_at IS NULL) = (adjusted_by IS NULL))
            );

            -- which demand a planned order covers, and how much of it (BR-DEM-12: unique per planned order and demand)
            CREATE TABLE mps_pegs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              planned_order_id uuid NOT NULL,
              mps_demand_id uuid,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              -- NULL demand: the planned order restores safety stock
              UNIQUE NULLS NOT DISTINCT (tenant_id, planned_order_id, mps_demand_id),
              FOREIGN KEY (tenant_id, planned_order_id, mps_version_id) REFERENCES mps_planned_orders (tenant_id, id, mps_version_id),
              FOREIGN KEY (tenant_id, mps_demand_id, mps_version_id) REFERENCES mps_demands (tenant_id, id, mps_version_id)
            );

            -- capacity load per work center and ISO week (G-MPS-7, D-123 (4)); available NULL = not configured
            CREATE TABLE mps_capacity (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              work_center_id uuid NOT NULL,
              week_start date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
              load_hours numeric(20, 6) NOT NULL CHECK (load_hours >= 0),
              available_hours numeric(20, 6) CHECK (available_hours >= 0),
              status text NOT NULL CHECK (status IN ('OK', 'OVERLOAD', 'NOT_CONFIGURED')),
              warnings text[] NOT NULL DEFAULT '{}',
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_version_id, work_center_id, week_start),
              FOREIGN KEY (tenant_id, mps_version_id) REFERENCES mps_versions (tenant_id, id),
              FOREIGN KEY (tenant_id, work_center_id) REFERENCES work_centers (tenant_id, id),
              CHECK ((status = 'NOT_CONFIGURED') = (available_hours IS NULL)),
              CHECK (status <> 'OVERLOAD' OR load_hours > available_hours)
            );

            -- everyone who prepared a version (calculated, submitted, adjusted a planned order), kept for its whole life:
            -- none of them approves it (SoD-07), even after a later recalculation or adjustment overwrote their name
            CREATE TABLE mps_contributors (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              mps_version_id uuid NOT NULL,
              user_id uuid NOT NULL,
              contribution text NOT NULL CHECK (contribution IN ('CALCULATED', 'SUBMITTED', 'ADJUSTED')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, mps_version_id, user_id, contribution),
              FOREIGN KEY (tenant_id, mps_version_id) REFERENCES mps_versions (tenant_id, id)
            );
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON mps_contributors FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE mps_contributors ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON mps_contributors TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON mps_contributors TO eora_app;

            -- records every name a row carries on each write (insert-only, duplicates ignored: never updated or deleted by the app)
            CREATE OR REPLACE FUNCTION eora_record_mps_contributor() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF TG_TABLE_NAME = 'mps_planned_orders' THEN
                IF NEW.adjusted_by IS NOT NULL THEN
                  INSERT INTO public.mps_contributors (tenant_id, mps_version_id, user_id, contribution)
                  VALUES (NEW.tenant_id, NEW.mps_version_id, NEW.adjusted_by, 'ADJUSTED') ON CONFLICT DO NOTHING;
                END IF;
                RETURN NULL;
              END IF;
              IF NEW.calculated_by IS NOT NULL THEN
                INSERT INTO public.mps_contributors (tenant_id, mps_version_id, user_id, contribution)
                VALUES (NEW.tenant_id, NEW.id, NEW.calculated_by, 'CALCULATED') ON CONFLICT DO NOTHING;
              END IF;
              IF NEW.submitted_by IS NOT NULL THEN
                INSERT INTO public.mps_contributors (tenant_id, mps_version_id, user_id, contribution)
                VALUES (NEW.tenant_id, NEW.id, NEW.submitted_by, 'SUBMITTED') ON CONFLICT DO NOTHING;
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_record_mps_contributor() FROM PUBLIC;
            CREATE TRIGGER record_mps_contributor AFTER INSERT OR UPDATE ON mps_versions FOR EACH ROW EXECUTE FUNCTION eora_record_mps_contributor();

            -- who writes an MPS, and how its status moves (BR-PLN-04, SoD-07)
            CREATE OR REPLACE FUNCTION eora_guard_mps_version() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.calculated_by IS DISTINCT FROM a OR NOT public.eora_actor_may('planning.mps.create', NEW.plant_id) THEN
                  RAISE EXCEPTION 'an MPS is calculated as a draft by a planner of the plant' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.plant_id, NEW.mps_no, NEW.horizon_weeks) IS DISTINCT FROM (OLD.plant_id, OLD.mps_no, OLD.horizon_weeks) THEN
                RAISE EXCEPTION 'an MPS keeps its plant, number and horizon' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('SUPERSEDED', 'CANCELLED') THEN
                RAISE EXCEPTION 'a superseded or cancelled MPS is history' USING ERRCODE = '23514';
              END IF;
              IF (NEW.horizon_start, NEW.plan_date, NEW.input_fingerprint, NEW.calculated_at, NEW.calculated_by)
                 IS DISTINCT FROM (OLD.horizon_start, OLD.plan_date, OLD.input_fingerprint, OLD.calculated_at, OLD.calculated_by) THEN
                IF OLD.status <> 'DRAFT' OR NEW.status <> 'DRAFT' OR NEW.calculated_by IS DISTINCT FROM a OR NOT public.eora_actor_may('planning.mps.create', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a draft MPS is recalculated by a planner of the plant' USING ERRCODE = '42501';
                END IF;
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                IF OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED' THEN
                  IF NEW.submitted_by IS DISTINCT FROM a OR NOT public.eora_actor_may('planning.mps.submit', NEW.plant_id) THEN
                    RAISE EXCEPTION 'an MPS is submitted by a planner of the plant' USING ERRCODE = '42501';
                  END IF;
                ELSIF OLD.status = 'DRAFT' AND NEW.status = 'CANCELLED' THEN
                  IF NEW.cancelled_by IS DISTINCT FROM a OR NOT public.eora_actor_may('planning.mps.create', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a draft MPS is cancelled by a planner of the plant' USING ERRCODE = '42501';
                  END IF;
                ELSIF OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED', 'DRAFT') THEN
                  IF NOT public.eora_actor_may('planning.mps.approve', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a submitted MPS is approved or returned by an approver of the plant' USING ERRCODE = '42501';
                  END IF;
                  IF NEW.status = 'APPROVED' AND (NEW.approved_by IS DISTINCT FROM a OR a IN (OLD.calculated_by, OLD.submitted_by)
                     OR EXISTS (SELECT 1 FROM public.mps_contributors k WHERE k.tenant_id = NEW.tenant_id AND k.mps_version_id = NEW.id AND k.user_id = a)
                     OR EXISTS (SELECT 1 FROM public.mps_planned_orders o WHERE o.tenant_id = NEW.tenant_id AND o.mps_version_id = NEW.id AND o.adjusted_by = a)) THEN
                    RAISE EXCEPTION 'an MPS is approved by someone who did not prepare it (SoD-07)' USING ERRCODE = '42501';
                  END IF;
                  IF NEW.status = 'DRAFT' AND (NEW.return_reason IS NULL OR NEW.submitted_by IS NOT NULL) THEN
                    RAISE EXCEPTION 'a returned MPS gives the reason' USING ERRCODE = '23514';
                  END IF;
                ELSIF NOT (OLD.status = 'APPROVED' AND NEW.status = 'SUPERSEDED') THEN
                  RAISE EXCEPTION 'an MPS goes DRAFT → SUBMITTED → APPROVED → SUPERSEDED' USING ERRCODE = '23514';
                END IF;
              ELSIF (NEW.submitted_at, NEW.approved_at, NEW.approved_by) IS DISTINCT FROM (OLD.submitted_at, OLD.approved_at, OLD.approved_by) THEN
                RAISE EXCEPTION 'a submission or approval is history' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_mps_version() FROM PUBLIC;
            CREATE TRIGGER guard_mps_version BEFORE INSERT OR UPDATE ON mps_versions FOR EACH ROW EXECUTE FUNCTION eora_guard_mps_version();

            -- the rows of a calculation change while their version is a draft only, by a planner of its plant
            CREATE OR REPLACE FUNCTION eora_guard_mps_rows() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              v record;
            BEGIN
              SELECT m.status, m.plant_id INTO v FROM public.mps_versions m
               WHERE m.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND m.id = COALESCE(NEW.mps_version_id, OLD.mps_version_id);
              -- a cascade from a deleted version never happens (versions are never deleted); a draft is the only writable state
              IF v.status IS DISTINCT FROM 'DRAFT' THEN
                RAISE EXCEPTION 'only a draft MPS changes its calculation' USING ERRCODE = '23514';
              END IF;
              IF NOT public.eora_actor_may('planning.mps.create', v.plant_id) THEN
                RAISE EXCEPTION 'an MPS is calculated by a planner of the plant' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'UPDATE' THEN
                IF TG_TABLE_NAME <> 'mps_planned_orders' OR (NEW.mps_version_id, NEW.mps_item_id, NEW.week_start, NEW.calc_qty, NEW.calc_due_date)
                     IS DISTINCT FROM (OLD.mps_version_id, OLD.mps_item_id, OLD.week_start, OLD.calc_qty, OLD.calc_due_date) THEN
                  RAISE EXCEPTION 'a calculation is replaced, not edited; a planned order changes its quantity and dates only' USING ERRCODE = '23514';
                END IF;
                IF NEW.adjusted_by IS DISTINCT FROM (SELECT c.actor FROM ctx_api.context() c) OR NEW.adjust_reason IS NULL THEN
                  RAISE EXCEPTION 'a planned order is adjusted with a reason by the planner' USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN COALESCE(NEW, OLD);
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_mps_rows() FROM PUBLIC;
            SQL);

        foreach (self::CHILDREN as $table) {
            $update = $table === 'mps_planned_orders' ? ', UPDATE' : '';
            DB::unprepared(<<<SQL
                CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
                CREATE TRIGGER guard_mps_rows BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION eora_guard_mps_rows();
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table} TO eora_app
                  USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
                GRANT SELECT, INSERT, DELETE{$update} ON {$table} TO eora_app;
                SQL);
        }
        DB::unprepared('CREATE TRIGGER record_mps_contributor AFTER INSERT OR UPDATE ON mps_planned_orders FOR EACH ROW EXECUTE FUNCTION eora_record_mps_contributor()');
    }

    public function down(): void
    {
        foreach (array_reverse(self::CHILDREN) as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS mps_contributors;
            DROP TABLE IF EXISTS mps_versions;
            DROP FUNCTION IF EXISTS eora_record_mps_contributor();
            DROP FUNCTION IF EXISTS eora_guard_mps_rows();
            DROP FUNCTION IF EXISTS eora_guard_mps_version();
            SQL);
    }
};

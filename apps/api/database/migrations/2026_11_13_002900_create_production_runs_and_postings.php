<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 4 (doc 33 §1.4, D-111, doc 04 rows 5 and 8, ADR-0003, ADR-0004, BR-CON-01/03/05, BR-OUT-01..04/08).
 *
 * - `production_runs`: a thin run (D-111) — OPEN for a released WO at one work center of its routing (machine optional),
 *   CLOSED by the production leader once it has a posting, or CANCELLED with a reason while it has none. The first run
 *   moves the WO to IN_PROGRESS.
 * - `PRODUCTION_POSTING` (one inventory document): measured consumption per input lot (WEIGHED_REMAINING,
 *   COUNTED_REMAINING, WEIGHED_CONSUMED with evidence; by-difference is M5) out of the run's Material at Production into
 *   V_CONSUMED; output Good / NG rework / NG reject pcs of the WO's item from V_PRODUCED into one output lot (QC area
 *   PENDING_QC when the item needs inspection, QC_RELEASED otherwise — M4-D6; NG area REWORK / REJECTED); process scrap
 *   in a SCRAP item at the scrap yard with a reason code; genealogy input lot → output lot.
 * - At most one regular posting per run, production date and shift (BR-OUT-08); a correction names the reversed
 *   posting it replaces and is exempt.
 *
 * The merged functions that refused production postings (M3) or knew only RELEASED work orders are patched in place,
 * each replacement asserted, and restored by down().
 */
return new class extends Migration
{
    /** [function, original text, replacement] patched in place. */
    private const array PATCHES = [
        // the ledger check refused production postings until their checks existed (this migration adds them)
        ['eora_check_inventory_posting()',
            "IF d.doc_type IN ('PRODUCTION_POSTING', 'SCRAP_DISPOSITION', 'REWORK_POSTING') THEN",
            "IF d.doc_type IN ('SCRAP_DISPOSITION', 'REWORK_POSTING') THEN"],
        // material is issued to a work order that is released or in production
        ['eora_guard_material_leg()',
            "IF w.status IS DISTINCT FROM 'RELEASED' THEN",
            "IF w.status IS NULL OR w.status NOT IN ('RELEASED', 'IN_PROGRESS') THEN"],
        // consumption at a work center names the work order whose material is consumed
        ['eora_guard_material_leg()',
            "IF d.doc_type IN ('LOT_SPLIT', 'STOCK_ADJUSTMENT') AND NEW.wo_id IS NOT NULL THEN",
            "IF d.doc_type IN ('LOT_SPLIT', 'STOCK_ADJUSTMENT', 'PRODUCTION_POSTING') AND NEW.wo_id IS NOT NULL THEN"],
        // the first run of a released work order moves it to IN_PROGRESS (D-111)
        ['eora_guard_work_order()',
            "ELSIF NEW.status = 'CANCELLED' AND OLD.status IN ('DRAFT', 'SUBMITTED', 'RELEASED') THEN",
            "ELSIF OLD.status = 'RELEASED' AND NEW.status = 'IN_PROGRESS' THEN
                  IF NOT public.eora_actor_may('production.run.post', NEW.plant_id)
                     OR NOT EXISTS (SELECT 1 FROM public.production_runs r WHERE r.tenant_id = NEW.tenant_id AND r.work_order_id = NEW.id) THEN
                    RAISE EXCEPTION 'a work order goes into production with its first run' USING ERRCODE = '42501';
                  END IF;
                ELSIF NEW.status = 'CANCELLED' AND OLD.status IN ('DRAFT', 'SUBMITTED', 'RELEASED') THEN", ],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE production_runs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              run_no text NOT NULL CHECK (run_no ~ '^RUN-[A-Z0-9_-]{1,20}-[0-9]{4}-[0-9]{4,}$'),
              work_order_id uuid NOT NULL,
              work_center_id uuid NOT NULL,
              machine_id uuid,
              status text NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSED', 'CANCELLED')),
              opened_by uuid NOT NULL,
              opened_at timestamptz NOT NULL,
              closed_by uuid,
              closed_at timestamptz,
              cancelled_by uuid,
              cancelled_at timestamptz,
              cancel_reason text CHECK (length(btrim(cancel_reason)) BETWEEN 3 AND 500),
              note text CHECK (length(note) <= 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, run_no),
              FOREIGN KEY (tenant_id, plant_id, work_order_id) REFERENCES work_orders (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, plant_id, work_center_id) REFERENCES work_centers (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, work_center_id, machine_id) REFERENCES machines (tenant_id, work_center_id, id),
              CHECK ((status = 'CLOSED') = (closed_by IS NOT NULL AND closed_at IS NOT NULL)),
              CHECK ((status = 'CANCELLED') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))
            );
            CREATE INDEX production_runs_wo_idx ON production_runs (tenant_id, work_order_id, status);

            -- a run: opened OPEN by a production leader of the plant for a released (or running) WO at a work center of its
            -- routing; closed once it has a posting, cancelled with a reason while it has none; otherwise history
            CREATE OR REPLACE FUNCTION eora_guard_production_run() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              w record;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT wo.status, wo.routing_version_id INTO w FROM public.work_orders wo WHERE wo.tenant_id = NEW.tenant_id AND wo.id = NEW.work_order_id;
                IF NEW.status <> 'OPEN' OR NEW.opened_by IS DISTINCT FROM a OR NOT public.eora_actor_may('production.run.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a run is opened by a production leader of its plant' USING ERRCODE = '42501';
                END IF;
                IF w.status IS NULL OR w.status NOT IN ('RELEASED', 'IN_PROGRESS') THEN
                  RAISE EXCEPTION 'a run is opened for a released work order' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.routing_operations o WHERE o.tenant_id = NEW.tenant_id AND o.routing_version_id = w.routing_version_id
                                 AND o.work_center_id = NEW.work_center_id) THEN
                  RAISE EXCEPTION 'a run is at a work center of its work order''s routing' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.plant_id, NEW.run_no, NEW.work_order_id, NEW.work_center_id, NEW.machine_id, NEW.opened_by, NEW.opened_at, NEW.note)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.run_no, OLD.work_order_id, OLD.work_center_id, OLD.machine_id, OLD.opened_by, OLD.opened_at, OLD.note) THEN
                RAISE EXCEPTION 'a run keeps what it was opened for' USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'OPEN' THEN
                RAISE EXCEPTION 'a closed or cancelled run is history' USING ERRCODE = '23514';
              END IF;
              IF NEW.status = 'CLOSED' THEN
                IF NEW.closed_by IS DISTINCT FROM a OR NOT public.eora_actor_may('production.run.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a run is closed by a production leader of its plant' USING ERRCODE = '42501';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.doc_type = 'PRODUCTION_POSTING'
                                 AND d.source_id = NEW.id AND d.status = 'POSTED') THEN
                  RAISE EXCEPTION 'a run is closed once it has a posting; cancel it otherwise' USING ERRCODE = '23514';
                END IF;
              ELSIF NEW.status = 'CANCELLED' THEN
                IF NEW.cancelled_by IS DISTINCT FROM a OR NOT public.eora_actor_may('production.run.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a run is cancelled by a production leader of its plant' USING ERRCODE = '42501';
                END IF;
                IF EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.doc_type = 'PRODUCTION_POSTING' AND d.source_id = NEW.id) THEN
                  RAISE EXCEPTION 'a run with postings is closed, not cancelled' USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_production_run() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON production_runs FOR EACH ROW EXECUTE FUNCTION eora_guard_production_run();

            DO $do$
            BEGIN
              EXECUTE 'CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON production_runs FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit()';
              EXECUTE 'ALTER TABLE production_runs ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY';
              EXECUTE 'CREATE POLICY tenant_isolation ON production_runs TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))';
              EXECUTE 'GRANT SELECT, INSERT ON production_runs TO eora_app';
            END
            $do$;
            GRANT UPDATE (status, closed_by, closed_at, cancelled_by, cancelled_at, cancel_reason) ON production_runs TO eora_app;

            -- ADR-0003 measurement on consumption legs (M4 methods; by-difference arrives with its case in M5), and the
            -- reason of process scrap (BR-OUT-04)
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_measurement_method_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_measurement_method_check
              CHECK (measurement_method IN ('WEIGHED_REMAINING', 'COUNTED_REMAINING', 'WEIGHED_CONSUMED', 'MEASURED_LENGTH', 'UNMEASURED_BY_DIFFERENCE'));
            ALTER TABLE inventory_movements ADD COLUMN measured_qty numeric(20, 6) CHECK (measured_qty >= 0);
            ALTER TABLE inventory_movements ADD COLUMN measurement_evidence text CHECK (length(btrim(measurement_evidence)) BETWEEN 1 AND 200);
            ALTER TABLE inventory_movements ADD COLUMN reason_code text CHECK (reason_code IN ('SKELETON', 'SETUP', 'EDGE_TRIM', 'DEFECT', 'OTHER'));
            ALTER TABLE inventory_movements ADD COLUMN reason_note text CHECK (length(btrim(reason_note)) BETWEEN 3 AND 500);
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_run_fk FOREIGN KEY (tenant_id, plant_id, run_id) REFERENCES production_runs (tenant_id, plant_id, id);

            -- BR-OUT-08: one regular posting per run, production date and shift; a correction names the reversed posting it
            -- replaces and is exempt
            ALTER TABLE inventory_documents ADD COLUMN corrects_document_id uuid;
            ALTER TABLE inventory_documents ADD CONSTRAINT inventory_documents_corrects_fk FOREIGN KEY (tenant_id, corrects_document_id) REFERENCES inventory_documents (tenant_id, id);
            CREATE UNIQUE INDEX inventory_documents_regular_production_uq ON inventory_documents (tenant_id, source_id, production_date, shift_id) NULLS NOT DISTINCT
              WHERE doc_type = 'PRODUCTION_POSTING' AND corrects_document_id IS NULL;
            -- a reversed posting is replaced once: corrections never add postings beyond the one they replace
            CREATE UNIQUE INDEX inventory_documents_correction_uq ON inventory_documents (tenant_id, corrects_document_id) WHERE corrects_document_id IS NOT NULL;

            -- who posts production, for which run, and what a correction replaces
            CREATE OR REPLACE FUNCTION eora_guard_production_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.doc_type <> 'PRODUCTION_POSTING' THEN
                IF NEW.corrects_document_id IS NOT NULL THEN
                  RAISE EXCEPTION 'only a production posting corrects another' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF TG_OP = 'UPDATE' THEN
                IF (NEW.source_type, NEW.source_id, NEW.corrects_document_id) IS DISTINCT FROM (OLD.source_type, OLD.source_id, OLD.corrects_document_id) THEN
                  RAISE EXCEPTION 'a production posting keeps its run' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.status <> 'POSTED' OR NOT public.eora_actor_may('production.posting.post', NEW.plant_id) THEN
                RAISE EXCEPTION 'production is posted by a production leader of its plant' USING ERRCODE = '42501';
              END IF;
              IF NEW.source_type IS DISTINCT FROM 'production_run' OR NOT EXISTS (
                   SELECT 1 FROM public.production_runs r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.source_id AND r.plant_id = NEW.plant_id AND r.status = 'OPEN') THEN
                RAISE EXCEPTION 'production is posted to an open run of its plant' USING ERRCODE = '23514';
              END IF;
              IF NEW.corrects_document_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM public.inventory_documents o WHERE o.tenant_id = NEW.tenant_id AND o.id = NEW.corrects_document_id
                      AND o.doc_type = 'PRODUCTION_POSTING' AND o.source_id = NEW.source_id AND o.status = 'REVERSED') THEN
                RAISE EXCEPTION 'a correction replaces a reversed posting of the same run' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_production_document() FROM PUBLIC;
            CREATE TRIGGER guard_production BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_production_document();

            -- the posting as a whole (doc 04 rows 5 and 8): measured consumption of the run's work order at its work center
            -- into V_CONSUMED; output of the WO's item from V_PRODUCED into one output lot by category; scrap with its reason;
            -- genealogy of every consumed lot into the output lot
            CREATE OR REPLACE FUNCTION eora_check_production_posting() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              r record;
            BEGIN
              IF NEW.status NOT IN ('POSTED', 'REVERSED') THEN
                RETURN NULL;
              END IF;
              SELECT pr.work_order_id, pr.work_center_id, wo.item_id, i.inspection_required INTO r
                FROM public.production_runs pr JOIN public.work_orders wo ON wo.tenant_id = pr.tenant_id AND wo.id = pr.work_order_id
                JOIN public.items i ON i.tenant_id = wo.tenant_id AND i.id = wo.item_id
               WHERE pr.tenant_id = NEW.tenant_id AND pr.id = NEW.source_id;
              IF EXISTS (
                   SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                     LEFT JOIN public.items mi ON mi.tenant_id = m.tenant_id AND mi.id = m.item_id
                    WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT (
                      m.run_id IS NOT DISTINCT FROM NEW.source_id AND CASE
                        -- consumption: out of the run's Material at Production for its WO, measured with evidence; into V_CONSUMED
                        WHEN m.movement_code = 'CONSUME' AND m.qty_signed < 0 THEN
                          l.location_type = 'MAT_AT_PROD' AND l.work_center_id = r.work_center_id AND m.wo_id = r.work_order_id AND m.stock_status = 'UNRESTRICTED'
                          AND m.measurement_method IN ('WEIGHED_REMAINING', 'COUNTED_REMAINING', 'WEIGHED_CONSUMED')
                          AND m.measured_qty IS NOT NULL AND m.measurement_evidence IS NOT NULL
                          AND (m.measurement_method <> 'WEIGHED_CONSUMED' OR m.measured_qty = -m.qty_signed)
                        WHEN m.movement_code = 'CONSUME' THEN
                          l.location_type = 'V_CONSUMED' AND m.wo_id IS NULL AND m.measurement_method IS NULL
                        -- output: out of V_PRODUCED, into its category's place and status
                        WHEN m.qty_signed < 0 THEN
                          l.location_type = 'V_PRODUCED' AND m.wo_id IS NULL
                        WHEN m.movement_code = 'OUTPUT_GOOD' THEN
                          m.item_id = r.item_id AND l.location_type = 'QC_AREA' AND m.stock_status = CASE WHEN r.inspection_required THEN 'PENDING_QC' ELSE 'QC_RELEASED' END
                        WHEN m.movement_code = 'OUTPUT_NG_REWORK' THEN
                          m.item_id = r.item_id AND l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'
                        WHEN m.movement_code = 'OUTPUT_NG_REJECT' THEN
                          m.item_id = r.item_id AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'
                        WHEN m.movement_code = 'OUTPUT_SCRAP' THEN
                          mi.item_type = 'SCRAP' AND l.location_type = 'SCRAP_YARD' AND m.stock_status = 'UNRESTRICTED' AND m.reason_code IS NOT NULL
                          AND (m.reason_code <> 'OTHER' OR m.reason_note IS NOT NULL)
                        ELSE false END)
                 )
                 -- each movement code moves as much out as in, per item and lot
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.movement_code, m.item_id, m.lot_id, m.uom_id HAVING sum(m.qty_signed) <> 0)
                 -- something consumed and something made
                 OR NOT EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.movement_code = 'CONSUME')
                 OR NOT EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.movement_code <> 'CONSUME')
                 -- one output lot for all pieces of the posting (D-111)
                 OR (SELECT count(DISTINCT m.lot_id) FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                       AND m.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT')) > 1
                 -- genealogy: every consumed lot into the output lot, with the consumed quantity
                 OR EXISTS (
                      SELECT 1 FROM public.inventory_movements c CROSS JOIN LATERAL (
                        SELECT o.lot_id FROM public.inventory_movements o WHERE o.tenant_id = NEW.tenant_id AND o.document_id = NEW.id
                           AND o.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') AND o.lot_id IS NOT NULL LIMIT 1) out_lot
                       WHERE c.tenant_id = NEW.tenant_id AND c.document_id = NEW.id AND c.movement_code = 'CONSUME' AND c.qty_signed < 0 AND c.lot_id IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM public.genealogy_edges g WHERE g.tenant_id = NEW.tenant_id AND g.document_id = NEW.id
                                           AND g.from_lot_id = c.lot_id AND g.to_lot_id = out_lot.lot_id AND g.qty_from = -c.qty_signed))
              THEN
                RAISE EXCEPTION 'a production posting consumes its run''s measured material and makes its work order''s item' USING ERRCODE = '23514';
              END IF;
              -- what is weighed or counted as remaining is what the work order has left of that lot at the work center
              IF NEW.status = 'POSTED' AND EXISTS (
                   SELECT 1 FROM public.inventory_movements m
                    WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.movement_code = 'CONSUME' AND m.qty_signed < 0
                      AND m.measurement_method IN ('WEIGHED_REMAINING', 'COUNTED_REMAINING')
                      AND m.measured_qty IS DISTINCT FROM (SELECT coalesce(sum(x.qty_signed), 0) FROM public.inventory_movements x
                                                            WHERE x.tenant_id = m.tenant_id AND x.wo_id = m.wo_id AND x.location_id = m.location_id
                                                              AND x.item_id = m.item_id AND x.lot_id IS NOT DISTINCT FROM m.lot_id AND x.stock_status = m.stock_status)) THEN
                RAISE EXCEPTION 'the remaining quantity is what the work order has left at the work center' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_production_posting() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_production_posting AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'PRODUCTION_POSTING') EXECUTE FUNCTION eora_check_production_posting();

            -- production fields only on production legs
            CREATE OR REPLACE FUNCTION eora_guard_production_leg() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              t text;
            BEGIN
              IF NEW.reverses_movement_id IS NOT NULL THEN
                RETURN NEW;
              END IF;
              SELECT d.doc_type INTO t FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.id = NEW.document_id;
              IF t <> 'PRODUCTION_POSTING' AND (NEW.run_id, NEW.measurement_method, NEW.measured_qty, NEW.measurement_evidence, NEW.reason_code, NEW.reason_note)
                 IS DISTINCT FROM (NULL::uuid, NULL::text, NULL::numeric, NULL::text, NULL::text, NULL::text) THEN
                RAISE EXCEPTION 'only a production posting records a run, measurement or scrap reason' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_production_leg() FROM PUBLIC;
            CREATE TRIGGER guard_production_leg BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION eora_guard_production_leg();
            SQL);

        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS guard_production_leg ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_guard_production_leg();
            DROP TRIGGER IF EXISTS check_production_posting ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_production_posting();
            DROP TRIGGER IF EXISTS guard_production ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_production_document();
            DROP INDEX IF EXISTS inventory_documents_correction_uq;
            DROP INDEX IF EXISTS inventory_documents_regular_production_uq;
            ALTER TABLE inventory_documents DROP CONSTRAINT IF EXISTS inventory_documents_corrects_fk;
            ALTER TABLE inventory_documents DROP COLUMN IF EXISTS corrects_document_id;
            ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_run_fk;
            ALTER TABLE inventory_movements DROP COLUMN IF EXISTS reason_note;
            ALTER TABLE inventory_movements DROP COLUMN IF EXISTS reason_code;
            ALTER TABLE inventory_movements DROP COLUMN IF EXISTS measurement_evidence;
            ALTER TABLE inventory_movements DROP COLUMN IF EXISTS measured_qty;
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_measurement_method_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_measurement_method_check
              CHECK (measurement_method IN ('WEIGHED', 'COUNTED', 'METERED', 'BY_DIFFERENCE', 'THEORETICAL')) NOT VALID; -- legs posted meanwhile keep their method
            DROP TABLE production_runs;
            DROP FUNCTION IF EXISTS eora_guard_production_run();
            SQL);
    }

    /** Replaces each original text in its function's definition (or back), asserting the text is there exactly once. */
    private function patch(bool $back): void
    {
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definition = (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definition, $find) !== 1) {
                throw new RuntimeException("Cannot patch {$function}: the expected text is not there exactly once.");
            }
            // the definition comes from the database itself (pg_get_functiondef), changed only by the constant texts above
            DB::connection()->getPdo()->exec(str_replace($find, $replace, $definition));
        }
    }
};

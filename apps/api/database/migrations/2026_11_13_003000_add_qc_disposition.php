<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 5 (doc 33 §1.5, D-113, doc 04 row 9, BR-QC-01..03, doc 14 #6, #20): quality disposition.
 *
 * - One `QC_DISPOSITION` document decides part or all of one lot waiting at the QC area: QC_RELEASE (→ QC area
 *   QC_RELEASED), QC_HOLD (→ QC area HOLD), QC_REWORK (→ NG area REWORK), QC_REJECT (→ NG area REJECTED); what is not
 *   decided stays PENDING_QC (BR-QC-01). A HOLD quantity may be rejected the same way.
 * - Posted by the inspector (`quality.inspection.post`), never by who reported that lot's output (SoD-01, BR-QC-03).
 * - Out of policy — releasing a HOLD quantity, or a REJECTED one back to released — is asked by the inspector
 *   (`quality.inspection.submit`, with the reason) and decided by a second person (`quality.disposition_release.approve`,
 *   QCS): nothing moves until it is approved (D-060).
 * - Hold, rework and reject carry a reason code DIMENSION / VISUAL / BURR / CRACK / MATERIAL / OTHER (note required).
 */
return new class extends Migration
{
    /** [function, original text, replacement] patched in place (asserted), restored by down(). */
    private const array PATCHES = [
        // a QC disposition records its reason codes on its legs
        ['eora_guard_production_leg()',
            "IF t <> 'PRODUCTION_POSTING' AND (",
            "IF t NOT IN ('PRODUCTION_POSTING', 'QC_DISPOSITION') AND ("],
        // process scrap takes the scrap reasons only (the QC reasons belong to dispositions)
        ['eora_check_production_posting()',
            'AND m.reason_code IS NOT NULL',
            "AND coalesce(m.reason_code IN ('SKELETON', 'SETUP', 'EDGE_TRIM', 'DEFECT', 'OTHER'), false)"],
        // a consumption leg without a measurement method is refused, not let through as unknown (NULL)
        ['eora_check_production_posting()',
            "AND m.measurement_method IN ('WEIGHED_REMAINING', 'COUNTED_REMAINING', 'WEIGHED_CONSUMED')",
            "AND coalesce(m.measurement_method IN ('WEIGHED_REMAINING', 'COUNTED_REMAINING', 'WEIGHED_CONSUMED'), false)"],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reason_code_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_code_check
              CHECK (reason_code IN ('SKELETON', 'SETUP', 'EDGE_TRIM', 'DEFECT', 'DIMENSION', 'VISUAL', 'BURR', 'CRACK', 'MATERIAL', 'OTHER'));

            -- who decides: the inspector posts in-policy dispositions and asks for out-of-policy ones; a second person with
            -- the release approval decides those (never who asked) and the reversal of any disposition
            CREATE OR REPLACE FUNCTION eora_guard_qc_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              -- D-113: the reversal of a disposition is decided by a quality supervisor, not by the inventory approver
              IF NEW.doc_type = 'REVERSAL' THEN
                IF TG_OP = 'UPDATE' AND OLD.status = 'SUBMITTED' AND NEW.status IS DISTINCT FROM OLD.status
                   AND EXISTS (SELECT 1 FROM public.inventory_documents o WHERE o.tenant_id = NEW.tenant_id AND o.id = NEW.reverses_document_id AND o.doc_type = 'QC_DISPOSITION')
                   AND NOT public.eora_actor_may('quality.disposition_release.approve', NEW.plant_id) THEN
                  RAISE EXCEPTION 'the reversal of a disposition is decided by a quality supervisor of its plant' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.doc_type <> 'QC_DISPOSITION' THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status = 'POSTED' AND NOT public.eora_actor_may('quality.inspection.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a disposition is posted by an inspector of its plant' USING ERRCODE = '42501';
                END IF;
                IF NEW.status = 'SUBMITTED' AND (NEW.requested_by IS DISTINCT FROM a OR NOT public.eora_actor_may('quality.inspection.submit', NEW.plant_id)) THEN
                  RAISE EXCEPTION 'a release out of policy is asked by an inspector of its plant' USING ERRCODE = '42501';
                END IF;
                IF NEW.status NOT IN ('POSTED', 'SUBMITTED') THEN
                  RAISE EXCEPTION 'a disposition is posted or asked' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'SUBMITTED' AND (NEW.note IS NULL OR length(btrim(NEW.note)) < 3) THEN
                  RAISE EXCEPTION 'a release out of policy gives its reason' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status AND OLD.status = 'SUBMITTED' THEN
                IF NEW.status NOT IN ('POSTED', 'REJECTED') OR NEW.decided_by IS DISTINCT FROM a OR a = OLD.requested_by
                   OR NOT public.eora_actor_may('quality.disposition_release.approve', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a release out of policy is decided by a second person with the release approval' USING ERRCODE = '42501';
                END IF;
                IF (NEW.request, NEW.note) IS DISTINCT FROM (OLD.request, OLD.note) THEN
                  RAISE EXCEPTION 'a release out of policy is decided as it was asked' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'SUBMITTED' AND (NEW.request, NEW.note) IS DISTINCT FROM (OLD.request, OLD.note) THEN
                RAISE EXCEPTION 'a release out of policy keeps what was asked' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_qc_document() FROM PUBLIC;
            CREATE TRIGGER guard_qc BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_qc_document();

            -- the posted disposition as a whole: one lot, from what waits for a decision, into each outcome's place and
            -- status, with its reason; out-of-policy releases only through an approved request; SoD-01
            CREATE OR REPLACE FUNCTION eora_check_qc_disposition() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              approved boolean := NEW.requested_by IS NOT NULL;
            BEGIN
              -- checked once, as posted (marking it reversed later moves nothing)
              IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF NEW.status <> 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF EXISTS (
                   SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                    WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT (
                      (m.run_id, m.measurement_method, m.measured_qty, m.measurement_evidence, m.wo_id) IS NOT DISTINCT FROM (NULL::uuid, NULL::text, NULL::numeric, NULL::text, NULL::uuid)
                      AND CASE
                        -- out of what waits: PENDING_QC or HOLD at the QC area; REJECTED at the NG area only for an approved release
                        WHEN m.qty_signed < 0 THEN
                          (l.location_type = 'QC_AREA' AND m.stock_status IN ('PENDING_QC', 'HOLD'))
                          OR (approved AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED')
                        WHEN m.movement_code = 'QC_RELEASE' THEN
                          l.location_type = 'QC_AREA' AND m.stock_status = 'QC_RELEASED'
                        WHEN m.movement_code = 'QC_HOLD' THEN
                          l.location_type = 'QC_AREA' AND m.stock_status = 'HOLD'
                        WHEN m.movement_code = 'QC_REWORK' THEN
                          l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'
                        WHEN m.movement_code = 'QC_REJECT' THEN
                          l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'
                        ELSE false END
                      -- hold, rework and reject give their reason (OTHER with a note); a release gives none
                      AND (m.qty_signed < 0 OR CASE WHEN m.movement_code = 'QC_RELEASE' THEN m.reason_code IS NULL
                           ELSE coalesce(m.reason_code IN ('DIMENSION', 'VISUAL', 'BURR', 'CRACK', 'MATERIAL', 'OTHER') AND (m.reason_code <> 'OTHER' OR m.reason_note IS NOT NULL), false) END))
                 )
                 -- each outcome moves as much out as in, and the document decides one lot of one item
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.movement_code, m.item_id, m.lot_id, m.uom_id HAVING sum(m.qty_signed) <> 0)
                 OR (SELECT count(DISTINCT (m.item_id, m.lot_id)) FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id) <> 1
                 -- BR-QC-02: from HOLD only reject in policy; a HOLD or REJECTED quantity is released only through an approved request,
                 -- and such a request only releases
                 OR EXISTS (SELECT 1 FROM public.inventory_movements o JOIN public.inventory_movements i ON i.tenant_id = o.tenant_id AND i.document_id = o.document_id
                                                                             AND i.movement_code = o.movement_code AND i.qty_signed > 0
                             WHERE o.tenant_id = NEW.tenant_id AND o.document_id = NEW.id AND o.qty_signed < 0 AND (
                               (o.stock_status = 'HOLD' AND NOT approved AND o.movement_code <> 'QC_REJECT')
                               OR (approved AND (o.stock_status = 'PENDING_QC' OR o.movement_code <> 'QC_RELEASE'))))
              THEN
                RAISE EXCEPTION 'a disposition decides one lot waiting for quality into its outcome''s place and status' USING ERRCODE = '23514';
              END IF;
              -- SoD-01, BR-QC-03: who reported the lot's output (asked or approved, or posted) does not decide its quality;
              -- output without a lot shares one balance per item, so any lotless output of that item in the plant counts
              IF EXISTS (SELECT 1 FROM public.inventory_movements m JOIN public.inventory_movements o ON o.tenant_id = m.tenant_id AND o.item_id = m.item_id
                           AND (o.lot_id = m.lot_id OR (m.lot_id IS NULL AND o.lot_id IS NULL))
                           AND o.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') AND o.qty_signed > 0
                           JOIN public.inventory_documents od ON od.tenant_id = o.tenant_id AND od.id = o.document_id AND od.plant_id = NEW.plant_id
                          WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND od.posted_by IN (NEW.requested_by, NEW.posted_by)) THEN
                RAISE EXCEPTION 'who reported the output does not decide its quality' USING ERRCODE = '42501';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_qc_disposition() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_qc_disposition AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'QC_DISPOSITION') EXECUTE FUNCTION eora_check_qc_disposition();
            SQL);

        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS check_qc_disposition ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_qc_disposition();
            DROP TRIGGER IF EXISTS guard_qc ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_qc_document();
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reason_code_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_code_check
              CHECK (reason_code IN ('SKELETON', 'SETUP', 'EDGE_TRIM', 'DEFECT', 'OTHER')) NOT VALID; -- legs posted meanwhile keep their reason
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

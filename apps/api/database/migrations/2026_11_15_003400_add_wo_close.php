<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 8 (doc 33 §1.8, D-063, D-117, BR-PLN-03, M4-D1): work order close and its exception.
 *
 * - PPC (`planning.wo.post`) closes a released or in-production work order directly, once it has no open run, no
 *   Material at Production left and no return or exception still waiting. Closed is history (D-109 guard).
 * - Material left at production is returned (D-110) or closed by an exception: PPC asks
 *   (`planning.wo_close_exception.request_correction`) with the reason; CSA or INV approves
 *   (`planning.wo_close_exception.approve`, never who asked, D-060). Approved, the `WO_CLOSE_EXCEPTION` document moves the
 *   work order's remaining material to the plant's scrap yard (`WO_CLOSE_SCRAP`), no longer the WO's.
 * - Nothing puts material back at production for a closed or cancelled work order (a reversal included).
 *
 * Existing tenants receive the template permissions through `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['planning.wo_close_exception.view', 'view', false, ['PPC', 'CSA', 'INV', 'AUD']],
        ['planning.wo_close_exception.request_correction', 'request_correction', true, ['PPC']],
        ['planning.wo_close_exception.approve', 'approve', true, ['CSA', 'INV']],
    ];

    /** [function, original text, replacement] patched in place (asserted), restored by down(). */
    private const array PATCHES = [
        // the exception posts its own movement code
        ['eora_check_inventory_posting()',
            "WHEN 'STOCK_ADJUSTMENT' THEN ARRAY['ADJ_PLUS', 'ADJ_MINUS']",
            "WHEN 'STOCK_ADJUSTMENT' THEN ARRAY['ADJ_PLUS', 'ADJ_MINUS']
                     WHEN 'WO_CLOSE_EXCEPTION' THEN ARRAY['WO_CLOSE_SCRAP']", ],
        // it takes a work order's material out of Material at Production (one work order, UNRESTRICTED)
        ['eora_guard_material_leg()',
            "IF d.doc_type IN ('LOT_SPLIT', 'STOCK_ADJUSTMENT', 'PRODUCTION_POSTING') AND NEW.wo_id IS NOT NULL THEN",
            "IF d.doc_type IN ('LOT_SPLIT', 'STOCK_ADJUSTMENT', 'PRODUCTION_POSTING', 'WO_CLOSE_EXCEPTION') AND NEW.wo_id IS NOT NULL THEN"],
        // it starts as a request, decided by a second person
        ['eora_guard_inventory_document()',
            "approved boolean := NEW.doc_type IN ('STOCK_ADJUSTMENT', 'REVERSAL')",
            "approved boolean := NEW.doc_type IN ('STOCK_ADJUSTMENT', 'REVERSAL', 'WO_CLOSE_EXCEPTION')"],
        // who closed it is written by the close only
        ['eora_guard_work_order()',
            "IF OLD.status IN ('CANCELLED', 'CLOSED') THEN",
            "IF NOT (OLD.status <> 'CLOSED' AND NEW.status = 'CLOSED') AND (NEW.closed_by, NEW.closed_at) IS DISTINCT FROM (OLD.closed_by, OLD.closed_at) THEN
                RAISE EXCEPTION 'who closed a work order is written by its close' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('CANCELLED', 'CLOSED') THEN", ],
        // BR-PLN-03, D-063, D-117: closed by a planner, with no open run, no material left at production, nothing waiting
        ['eora_guard_work_order()',
            "ELSIF NEW.status = 'CANCELLED' AND OLD.status IN ('DRAFT', 'SUBMITTED', 'RELEASED') THEN",
            "ELSIF NEW.status = 'CLOSED' AND OLD.status IN ('RELEASED', 'IN_PROGRESS', 'ON_HOLD', 'PRODUCTION_COMPLETE') THEN
                  IF NEW.closed_by IS DISTINCT FROM a OR NEW.closed_at IS NULL OR NOT public.eora_actor_may('planning.wo.post', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a work order is closed by a planner of its plant' USING ERRCODE = '42501';
                  END IF;
                  IF EXISTS (SELECT 1 FROM public.production_runs r WHERE r.tenant_id = NEW.tenant_id AND r.work_order_id = NEW.id AND r.status = 'OPEN')
                     OR EXISTS (SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                                 WHERE m.tenant_id = NEW.tenant_id AND m.wo_id = NEW.id AND l.location_type = 'MAT_AT_PROD'
                                 GROUP BY m.location_id, m.item_id, m.lot_id HAVING sum(m.qty_signed) <> 0)
                     OR EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.status = 'SUBMITTED'
                                 AND ((d.doc_type = 'MAT_RETURN' AND d.source_id = NEW.id)
                                      OR (d.doc_type = 'WO_CLOSE_EXCEPTION' AND d.request -> 'legs' -> 0 ->> 'woId' = NEW.id::text))) THEN
                    RAISE EXCEPTION 'a work order closes with no open run, no material left at production and nothing waiting' USING ERRCODE = '23514';
                  END IF;
                ELSIF NEW.status = 'CANCELLED' AND OLD.status IN ('DRAFT', 'SUBMITTED', 'RELEASED') THEN", ],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$code, $action, $sensitive, $templates]) {
            DB::insert("insert into permissions (code, module, action, sensitive) values (?, 'planning', ?, ?) on conflict (code) do nothing", [$code, $action, $sensitive]);
            foreach ($templates as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
            }
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE work_orders ADD COLUMN closed_by uuid, ADD COLUMN closed_at timestamptz,
              ADD CONSTRAINT work_orders_closed_check CHECK ((status = 'CLOSED') = (closed_by IS NOT NULL AND closed_at IS NOT NULL));
            GRANT UPDATE (closed_by, closed_at) ON work_orders TO eora_app;

            ALTER TABLE inventory_documents DROP CONSTRAINT inventory_documents_doc_type_check;
            ALTER TABLE inventory_documents ADD CONSTRAINT inventory_documents_doc_type_check CHECK (doc_type IN ('RM_RECEIPT', 'INCOMING_RELEASE', 'MAT_ISSUE',
              'CUSTODY_ACCEPT', 'MAT_TRANSFER', 'MAT_RETURN', 'LOT_SPLIT', 'PRODUCTION_POSTING', 'QC_DISPOSITION', 'FG_RECEIPT', 'PICK', 'SHIPMENT',
              'SCRAP_DISPOSITION', 'REWORK_POSTING', 'STOCK_ADJUSTMENT', 'REVERSAL', 'WO_CLOSE_EXCEPTION'));
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_movement_code_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_movement_code_check CHECK (movement_code IN ('RCPT_SUPPLIER', 'INCOMING_RELEASE',
              'ISSUE_TO_PROD', 'CUSTODY_ACCEPT', 'TRANSFER_LOC', 'TRANSFER_PLANT_OUT', 'TRANSFER_PLANT_IN', 'CONSUME', 'RETURN_FROM_PROD', 'LOT_SPLIT_OUT',
              'LOT_SPLIT_IN', 'OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT', 'OUTPUT_SCRAP', 'OUTPUT_OTHER', 'QC_RELEASE', 'QC_HOLD', 'QC_REWORK',
              'QC_REJECT', 'REWORK_IN', 'REWORK_OUT', 'FG_RECEIPT', 'PICK', 'SHIP', 'ADJ_PLUS', 'ADJ_MINUS', 'SCRAP_DISPOSAL', 'WO_CLOSE_SCRAP'));

            -- who asks for and decides a close exception: a planner of the plant asks, with the reason; CSA or INV decides
            CREATE OR REPLACE FUNCTION eora_guard_wo_close_exception() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.doc_type <> 'WO_CLOSE_EXCEPTION' THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NOT public.eora_actor_may('planning.wo_close_exception.request_correction', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a close exception is asked by a planner of its plant' USING ERRCODE = '42501';
                END IF;
                IF coalesce(length(btrim(NEW.note)), 0) < 3 THEN
                  RAISE EXCEPTION 'a close exception gives its reason' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'SUBMITTED' AND NEW.status IN ('POSTED', 'REJECTED')
                    AND NOT public.eora_actor_may('planning.wo_close_exception.approve', NEW.plant_id) THEN
                RAISE EXCEPTION 'a close exception is decided by an approver of its plant' USING ERRCODE = '42501';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_wo_close_exception() FROM PUBLIC;
            CREATE TRIGGER guard_wo_close_exception BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_wo_close_exception();

            -- the posted exception as a whole: one work order's UNRESTRICTED material out of Material at Production into a
            -- scrap yard of the plant (no longer the work order's), each item and lot netting to zero
            CREATE OR REPLACE FUNCTION eora_check_wo_close_exception() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF NEW.status <> 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF EXISTS (SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                          WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT coalesce(
                            m.movement_code = 'WO_CLOSE_SCRAP' AND m.stock_status = 'UNRESTRICTED' AND m.run_id IS NULL AND l.plant_id = NEW.plant_id
                            AND CASE WHEN m.qty_signed < 0 THEN l.location_type = 'MAT_AT_PROD' AND m.wo_id IS NOT NULL
                                     ELSE l.location_type = 'SCRAP_YARD' AND m.wo_id IS NULL END, false))
                 OR (SELECT count(DISTINCT m.wo_id) FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id) <> 1
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.item_id, m.lot_id HAVING sum(m.qty_signed) <> 0)
              THEN
                RAISE EXCEPTION 'a close exception moves one work order''s material from Material at Production to a scrap yard' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_wo_close_exception() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_wo_close_exception AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'WO_CLOSE_EXCEPTION') EXECUTE FUNCTION eora_check_wo_close_exception();

            -- a closed or cancelled work order gets no material back at production (an issue, a reversal of a return, …)
            CREATE OR REPLACE FUNCTION eora_guard_closed_work_order_material() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.qty_signed > 0
                 AND EXISTS (SELECT 1 FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id AND l.location_type = 'MAT_AT_PROD')
                 AND EXISTS (SELECT 1 FROM public.work_orders w WHERE w.tenant_id = NEW.tenant_id AND w.id = NEW.wo_id AND w.status IN ('CLOSED', 'CANCELLED')) THEN
                RAISE EXCEPTION 'a closed or cancelled work order gets no material at production' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_closed_work_order_material() FROM PUBLIC;
            CREATE TRIGGER guard_closed_work_order_material BEFORE INSERT ON inventory_movements FOR EACH ROW WHEN (NEW.wo_id IS NOT NULL)
              EXECUTE FUNCTION eora_guard_closed_work_order_material();
            SQL);

        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS guard_closed_work_order_material ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_guard_closed_work_order_material();
            DROP TRIGGER IF EXISTS check_wo_close_exception ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_wo_close_exception();
            DROP TRIGGER IF EXISTS guard_wo_close_exception ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_wo_close_exception();
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_movement_code_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_movement_code_check CHECK (movement_code IN ('RCPT_SUPPLIER', 'INCOMING_RELEASE',
              'ISSUE_TO_PROD', 'CUSTODY_ACCEPT', 'TRANSFER_LOC', 'TRANSFER_PLANT_OUT', 'TRANSFER_PLANT_IN', 'CONSUME', 'RETURN_FROM_PROD', 'LOT_SPLIT_OUT',
              'LOT_SPLIT_IN', 'OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT', 'OUTPUT_SCRAP', 'OUTPUT_OTHER', 'QC_RELEASE', 'QC_HOLD', 'QC_REWORK',
              'QC_REJECT', 'REWORK_IN', 'REWORK_OUT', 'FG_RECEIPT', 'PICK', 'SHIP', 'ADJ_PLUS', 'ADJ_MINUS', 'SCRAP_DISPOSAL')) NOT VALID; -- legs posted meanwhile keep their code
            ALTER TABLE inventory_documents DROP CONSTRAINT inventory_documents_doc_type_check;
            ALTER TABLE inventory_documents ADD CONSTRAINT inventory_documents_doc_type_check CHECK (doc_type IN ('RM_RECEIPT', 'INCOMING_RELEASE', 'MAT_ISSUE',
              'CUSTODY_ACCEPT', 'MAT_TRANSFER', 'MAT_RETURN', 'LOT_SPLIT', 'PRODUCTION_POSTING', 'QC_DISPOSITION', 'FG_RECEIPT', 'PICK', 'SHIPMENT',
              'SCRAP_DISPOSITION', 'REWORK_POSTING', 'STOCK_ADJUSTMENT', 'REVERSAL')) NOT VALID;
            REVOKE UPDATE (closed_by, closed_at) ON work_orders FROM eora_app;
            ALTER TABLE work_orders DROP CONSTRAINT work_orders_closed_check, DROP COLUMN closed_at, DROP COLUMN closed_by;
            SQL);
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

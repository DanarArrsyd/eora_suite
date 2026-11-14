<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 6 (doc 33 §1.6, D-114, doc 04 row 10, BR-FG-01, doc 14 #7): FG receipt.
 *
 * - One `FG_RECEIPT` document moves QC_RELEASED finished goods from the QC area into one FG warehouse location as
 *   UNRESTRICTED; several lots in one document, each part or all of what was released (the rest stays QC_RELEASED).
 * - Posted directly by the FG warehouse (`warehouse.fg_receipt.post`, FGW); no second person (D-114).
 * - QC accept alone never changes FG stock (BR-FG-01): only this document does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- who writes an FG receipt: the FG warehouse of its plant, posting it directly
            CREATE OR REPLACE FUNCTION eora_guard_fg_receipt_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.doc_type <> 'FG_RECEIPT' THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'POSTED' OR NEW.requested_by IS NOT NULL THEN
                  RAISE EXCEPTION 'an FG receipt is posted directly' USING ERRCODE = '23514';
                END IF;
                IF NOT public.eora_actor_may('warehouse.fg_receipt.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'an FG receipt is posted by the FG warehouse of its plant' USING ERRCODE = '42501';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_fg_receipt_document() FROM PUBLIC;
            CREATE TRIGGER guard_fg_receipt BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_fg_receipt_document();

            -- the posted receipt as a whole: QC_RELEASED finished goods out of the QC area, into one FG location of the
            -- plant as UNRESTRICTED, each item and lot netting to zero
            CREATE OR REPLACE FUNCTION eora_check_fg_receipt() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              -- checked once, as posted (marking it reversed later moves nothing)
              IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF NEW.status <> 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF EXISTS (
                   SELECT 1 FROM public.inventory_movements m
                     JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                     JOIN public.items i ON i.tenant_id = m.tenant_id AND i.id = m.item_id
                    WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT coalesce(
                      m.movement_code = 'FG_RECEIPT' AND m.wo_id IS NULL AND m.run_id IS NULL AND i.item_type = 'FINISHED' AND l.plant_id = NEW.plant_id
                      AND CASE WHEN m.qty_signed < 0 THEN l.location_type = 'QC_AREA' AND m.stock_status = 'QC_RELEASED'
                               ELSE l.location_type = 'FG_WAREHOUSE' AND m.stock_status = 'UNRESTRICTED' END, false))
                 OR (SELECT count(DISTINCT m.location_id) FROM public.inventory_movements m
                      WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.qty_signed > 0) <> 1
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.item_id, m.lot_id HAVING sum(m.qty_signed) <> 0)
              THEN
                RAISE EXCEPTION 'an FG receipt moves QC-released finished goods from the QC area into one FG location as unrestricted stock' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_fg_receipt() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_fg_receipt AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'FG_RECEIPT') EXECUTE FUNCTION eora_check_fg_receipt();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS check_fg_receipt ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_fg_receipt();
            DROP TRIGGER IF EXISTS guard_fg_receipt ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_fg_receipt_document();
            SQL);
    }
};

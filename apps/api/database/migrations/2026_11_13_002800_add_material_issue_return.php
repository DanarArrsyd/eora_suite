<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 3 (doc 33 §1.3, D-110): material issue (doc 04 row 3, `MAT_ISSUE`) and return (row 6, `MAT_RETURN`).
 *
 * - Stock at Material at Production belongs to a work order (M4-D3, BR-ISS-02): every leg there names its WO
 *   (`inventory_movements.wo_id`, now a foreign key with the leg's plant); the balance stays keyed by work center + lot.
 * - An issue moves UNRESTRICTED material of the WO's material lines from a raw-material location to the
 *   Material at Production location of a work center of the WO's routing, while the WO is RELEASED (BR-ISS-01/04).
 * - A return moves it back to a raw-material location; it is asked by production (`materials.return.submit`) and received
 *   by materials (`materials.return.post`) with the measured quantity (BR-ISS-05).
 * - A work order never has less than nothing of a lot at a work center (returns, reversals); a reversal leg keeps the
 *   WO of the leg it reverses.
 * - Who may issue, ask, receive or refuse is checked against the signed actor's permission in the plant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_wo_fk
              FOREIGN KEY (tenant_id, plant_id, wo_id) REFERENCES work_orders (tenant_id, plant_id, id);
            CREATE INDEX inventory_movements_wo_idx ON inventory_movements (tenant_id, wo_id, location_id, item_id) WHERE wo_id IS NOT NULL;

            -- each leg of an issue or return, and every leg at Material at Production
            CREATE OR REPLACE FUNCTION eora_guard_material_leg() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              d record;
              loc record;
              w record;
              to_production boolean;
            BEGIN
              SELECT l.location_type, l.work_center_id INTO loc FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id;
              SELECT doc.doc_type, doc.source_type, doc.source_id INTO d FROM public.inventory_documents doc
               WHERE doc.tenant_id = NEW.tenant_id AND doc.id = NEW.document_id;
              IF NEW.reverses_movement_id IS NOT NULL THEN
                IF NEW.wo_id IS DISTINCT FROM (SELECT m.wo_id FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.reverses_movement_id) THEN
                  RAISE EXCEPTION 'a reversal leg keeps the work order of the leg it reverses' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF loc.location_type = 'MAT_AT_PROD' AND NEW.wo_id IS NULL THEN
                RAISE EXCEPTION 'stock at Material at Production belongs to a work order' USING ERRCODE = '23514';
              END IF;
              -- a lot split at a work center keeps its work order on the parent and every child (doc 14 #22, remnants); an
              -- adjustment there corrects one work order's material (its other leg is the virtual adjustment location)
              IF d.doc_type IN ('LOT_SPLIT', 'STOCK_ADJUSTMENT') AND NEW.wo_id IS NOT NULL THEN
                IF loc.location_type IS DISTINCT FROM 'MAT_AT_PROD' OR EXISTS (
                     SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.document_id
                        AND m.wo_id IS NOT NULL AND m.wo_id IS DISTINCT FROM NEW.wo_id) OR (d.doc_type = 'LOT_SPLIT' AND EXISTS (
                     SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.document_id AND m.wo_id IS NULL)) THEN
                  RAISE EXCEPTION 'a split or adjustment at a work center keeps one work order' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF d.doc_type NOT IN ('MAT_ISSUE', 'MAT_RETURN') THEN
                IF NEW.wo_id IS NOT NULL THEN
                  RAISE EXCEPTION 'a % does not name a work order', d.doc_type USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.wo_id IS NULL OR d.source_type IS DISTINCT FROM 'work_order' OR NEW.wo_id IS DISTINCT FROM d.source_id THEN
                RAISE EXCEPTION 'an issue or return moves the material of its work order' USING ERRCODE = '23514';
              END IF;
              IF NEW.stock_status <> 'UNRESTRICTED' THEN
                RAISE EXCEPTION 'an issue or return moves UNRESTRICTED material' USING ERRCODE = '23514';
              END IF;
              -- an issue goes out of a raw-material location into Material at Production; a return the other way
              to_production := (NEW.qty_signed > 0) = (d.doc_type = 'MAT_ISSUE');
              IF NOT to_production AND loc.location_type IS DISTINCT FROM 'RM_WAREHOUSE' THEN
                RAISE EXCEPTION 'an issue takes from, and a return goes to, a raw-material location' USING ERRCODE = '23514';
              END IF;
              IF to_production AND loc.location_type IS DISTINCT FROM 'MAT_AT_PROD' THEN
                RAISE EXCEPTION 'an issue goes to, and a return comes from, Material at Production' USING ERRCODE = '23514';
              END IF;
              IF d.doc_type = 'MAT_ISSUE' THEN
                SELECT wo.status, wo.routing_version_id INTO w FROM public.work_orders wo WHERE wo.tenant_id = NEW.tenant_id AND wo.id = NEW.wo_id;
                IF w.status IS DISTINCT FROM 'RELEASED' THEN
                  RAISE EXCEPTION 'material is issued to a released work order' USING ERRCODE = '23514';
                END IF;
                -- D-110: only the WO's material lines (its BOM snapshot); no substitution in M4
                IF NOT EXISTS (SELECT 1 FROM public.work_order_materials m WHERE m.tenant_id = NEW.tenant_id AND m.work_order_id = NEW.wo_id AND m.component_item_id = NEW.item_id) THEN
                  RAISE EXCEPTION 'an issue moves a material of the work order''s material lines' USING ERRCODE = '23514';
                END IF;
                -- D-110: to a work center of the WO's routing
                IF to_production AND NOT EXISTS (SELECT 1 FROM public.routing_operations o WHERE o.tenant_id = NEW.tenant_id
                                                     AND o.routing_version_id = w.routing_version_id AND o.work_center_id = loc.work_center_id) THEN
                  RAISE EXCEPTION 'material is issued to a work center of the work order''s routing' USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_material_leg() FROM PUBLIC;
            CREATE TRIGGER guard_material_leg BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION eora_guard_material_leg();

            -- what leaves Material at Production for a WO (a return, a reversal) never exceeds what that WO has there
            CREATE OR REPLACE FUNCTION eora_check_material_at_production() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id AND l.location_type = 'MAT_AT_PROD') THEN
                RETURN NULL;
              END IF;
              IF (SELECT sum(m.qty_signed) FROM public.inventory_movements m
                   WHERE m.tenant_id = NEW.tenant_id AND m.wo_id = NEW.wo_id AND m.location_id = NEW.location_id AND m.item_id = NEW.item_id
                     AND m.lot_id IS NOT DISTINCT FROM NEW.lot_id AND m.stock_status = NEW.stock_status AND m.uom_id = NEW.uom_id) < 0 THEN
                RAISE EXCEPTION 'a work order takes from Material at Production no more than it has there' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_material_at_production() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_material_at_production AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.wo_id IS NOT NULL AND NEW.qty_signed < 0) EXECUTE FUNCTION eora_check_material_at_production();

            -- an issue or return document: its work order, one work center, and who may write it
            CREATE OR REPLACE FUNCTION eora_guard_material_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF NEW.doc_type NOT IN ('MAT_ISSUE', 'MAT_RETURN') THEN
                RETURN NEW;
              END IF;
              IF NEW.source_type IS DISTINCT FROM 'work_order' OR NEW.source_id IS NULL
                 OR NOT EXISTS (SELECT 1 FROM public.work_orders wo WHERE wo.tenant_id = NEW.tenant_id AND wo.id = NEW.source_id AND wo.plant_id = NEW.plant_id) THEN
                RAISE EXCEPTION 'an issue or return belongs to a work order of its plant' USING ERRCODE = '23514';
              END IF;
              IF TG_OP = 'UPDATE' AND (NEW.source_type, NEW.source_id) IS DISTINCT FROM (OLD.source_type, OLD.source_id) THEN
                RAISE EXCEPTION 'an issue or return keeps its work order' USING ERRCODE = '23514';
              END IF;
              IF NEW.doc_type = 'MAT_ISSUE' THEN
                IF TG_OP = 'INSERT' AND (NEW.status <> 'POSTED' OR NOT public.eora_actor_may('materials.issue.post', NEW.plant_id)
                                         OR NOT public.eora_actor_may('materials.issue.create', NEW.plant_id)) THEN
                  RAISE EXCEPTION 'material is issued by materials of its plant' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              -- D-110: production asks for the return, materials receives (or refuses) it
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'SUBMITTED' OR NEW.requested_by IS NULL OR NOT public.eora_actor_may('materials.return.submit', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a return is asked by production of its plant' USING ERRCODE = '42501';
                END IF;
              ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                IF OLD.status <> 'SUBMITTED' OR NEW.status NOT IN ('POSTED', 'REJECTED') THEN
                  IF NOT (OLD.status = 'POSTED' AND NEW.status = 'REVERSED') THEN
                    RAISE EXCEPTION 'a return is received or refused once' USING ERRCODE = '23514';
                  END IF;
                ELSIF NEW.decided_by IS DISTINCT FROM a OR a = OLD.requested_by OR NOT public.eora_actor_may('materials.return.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a return is received or refused by materials of its plant, not by who asked for it' USING ERRCODE = '42501';
                ELSIF NEW.request IS DISTINCT FROM OLD.request THEN
                  RAISE EXCEPTION 'a return keeps what was asked' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'SUBMITTED' AND (NEW.request, NEW.note) IS DISTINCT FROM (OLD.request, OLD.note) THEN
                RAISE EXCEPTION 'a return keeps what was asked' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_material_document() FROM PUBLIC;
            CREATE TRIGGER guard_material_document BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_material_document();

            -- a posted issue or return: every leg names its work order, and it serves one work center
            CREATE OR REPLACE FUNCTION eora_check_material_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.status = 'POSTED' AND (
                   EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             AND m.wo_id IS DISTINCT FROM NEW.source_id)
                   OR (SELECT count(DISTINCT m.location_id) FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                        WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND l.location_type = 'MAT_AT_PROD') <> 1) THEN
                RAISE EXCEPTION 'an issue or return moves its work order''s material at one work center' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_material_document() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_material_document AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type IN ('MAT_ISSUE', 'MAT_RETURN')) EXECUTE FUNCTION eora_check_material_document();

            -- doc 16: a released work order is cancelled only while no issue is posted for it (a reversed issue no longer counts)
            CREATE OR REPLACE FUNCTION eora_guard_work_order_issues() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.doc_type = 'MAT_ISSUE'
                           AND d.status = 'POSTED' AND d.source_id = NEW.id) THEN
                RAISE EXCEPTION 'a work order with posted material issues is not cancelled' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_work_order_issues() FROM PUBLIC;
            CREATE TRIGGER guard_issues BEFORE UPDATE OF status ON work_orders FOR EACH ROW
              WHEN (NEW.status = 'CANCELLED' AND OLD.status IS DISTINCT FROM 'CANCELLED') EXECUTE FUNCTION eora_guard_work_order_issues();

            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS guard_issues ON work_orders;
            DROP FUNCTION IF EXISTS eora_guard_work_order_issues();
            DROP TRIGGER IF EXISTS check_material_document ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_material_document();
            DROP TRIGGER IF EXISTS guard_material_document ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_material_document();
            DROP TRIGGER IF EXISTS check_material_at_production ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_check_material_at_production();
            DROP TRIGGER IF EXISTS guard_material_leg ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_guard_material_leg();
            DROP INDEX IF EXISTS inventory_movements_wo_idx;
            ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_wo_fk;
            SQL);
    }
};

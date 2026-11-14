<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 7b (doc 33 §1.7, D-115, D-116, doc 04 row 11, BR-FG-03, BR-DEM-09/10, doc 14 #32, #35): shipment.
 *
 * - One `SHIPMENT` document ships staged goods of one customer — several schedule lines, possibly of different orders —
 *   from STAGING to V_CUSTOMER (`SHIP`), each leg naming its schedule line. Posted directly by delivery or the FG
 *   warehouse (`delivery.shipment.post`). Its transport (vehicle number, driver) is kept beside it.
 * - Delivered = Σ shipped − Σ reversed per schedule line (BR-DEM-10): the line's status follows it (FIRM →
 *   PARTIALLY_FULFILLED → FULFILLED, back on a reversal). Tolerance 0 (M4-D9): what a line has committed (allocated,
 *   staged, delivered) never exceeds its requirement (eora_check_staged).
 * - A wrong shipment is corrected through the M3 reversal flow (asked, approved by a second person; doc 14 #35, #42).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipment_details (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              document_id uuid NOT NULL,
              vehicle_no text CHECK (length(vehicle_no) BETWEEN 1 AND 30 AND vehicle_no = btrim(vehicle_no)),
              driver_name text CHECK (length(driver_name) BETWEEN 1 AND 80 AND driver_name = btrim(driver_name)),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, document_id),
              FOREIGN KEY (tenant_id, document_id) REFERENCES inventory_documents (tenant_id, id)
            );
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON shipment_details FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE shipment_details ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON shipment_details TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON shipment_details TO eora_app;

            -- the transport of a shipment is written with it, by who posts it
            CREATE OR REPLACE FUNCTION eora_guard_shipment_details() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.id = NEW.document_id AND d.doc_type = 'SHIPMENT'
                               AND d.posted_by = (SELECT c.actor FROM ctx_api.context() c) AND d.posted_at = pg_catalog.now()) THEN
                RAISE EXCEPTION 'the transport of a shipment is written with its posting' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_shipment_details() FROM PUBLIC;
            CREATE TRIGGER guard_shipment_details BEFORE INSERT ON shipment_details FOR EACH ROW EXECUTE FUNCTION eora_guard_shipment_details();

            -- who writes a shipment: delivery or the FG warehouse of its plant, posting it directly, for one customer
            CREATE OR REPLACE FUNCTION eora_guard_shipment_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.doc_type <> 'SHIPMENT' THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'POSTED' OR NEW.requested_by IS NOT NULL OR NEW.source_type IS DISTINCT FROM 'customer'
                   OR NOT EXISTS (SELECT 1 FROM public.customers c WHERE c.tenant_id = NEW.tenant_id AND c.id = NEW.source_id) THEN
                  RAISE EXCEPTION 'a shipment is posted directly, for one customer' USING ERRCODE = '23514';
                END IF;
                IF NOT public.eora_actor_may('delivery.shipment.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a shipment is posted by delivery or the FG warehouse of its plant' USING ERRCODE = '42501';
                END IF;
              ELSIF (NEW.source_type, NEW.source_id) IS DISTINCT FROM (OLD.source_type, OLD.source_id) THEN
                RAISE EXCEPTION 'a shipment keeps its customer' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_shipment_document() FROM PUBLIC;
            CREATE TRIGGER guard_shipment BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_shipment_document();

            -- the posted shipment as a whole: UNRESTRICTED goods of its customer's schedule lines from staging to the
            -- plant's customer location, each schedule line, item and lot netting to zero
            CREATE OR REPLACE FUNCTION eora_check_shipment() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF NEW.status <> 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF EXISTS (SELECT 1 FROM public.inventory_movements m
                           JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                           LEFT JOIN public.delivery_schedule_lines sl ON sl.tenant_id = m.tenant_id AND sl.id = m.schedule_line_id
                           LEFT JOIN public.sales_order_lines ol ON ol.tenant_id = sl.tenant_id AND ol.id = sl.so_line_id
                          WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT coalesce(
                            m.movement_code = 'SHIP' AND m.stock_status = 'UNRESTRICTED' AND m.wo_id IS NULL AND m.run_id IS NULL AND l.plant_id = NEW.plant_id
                            AND ol.customer_id = NEW.source_id
                            AND l.location_type = CASE WHEN m.qty_signed < 0 THEN 'STAGING' ELSE 'V_CUSTOMER' END, false))
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.schedule_line_id, m.item_id, m.lot_id HAVING sum(m.qty_signed) <> 0)
              THEN
                RAISE EXCEPTION 'a shipment moves staged goods of its customer''s schedule lines from staging to the customer' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_shipment() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_shipment AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'SHIPMENT') EXECUTE FUNCTION eora_check_shipment();

            -- BR-DEM-10: a schedule line's status follows what was delivered (a shipment, or its reversal)
            CREATE OR REPLACE FUNCTION eora_follow_delivered() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              delivered numeric;
              s record;
              target text;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id AND l.location_type = 'V_CUSTOMER') THEN
                RETURN NULL;
              END IF;
              SELECT sl.status, sl.required_qty INTO s FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id;
              SELECT coalesce(sum(m.qty_signed), 0) INTO delivered FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
               WHERE m.tenant_id = NEW.tenant_id AND m.schedule_line_id = NEW.schedule_line_id AND l.location_type = 'V_CUSTOMER';
              target := CASE WHEN delivered >= s.required_qty THEN 'FULFILLED' WHEN delivered > 0 THEN 'PARTIALLY_FULFILLED' ELSE 'FIRM' END;
              IF s.status IN ('FIRM', 'PARTIALLY_FULFILLED', 'FULFILLED') AND s.status <> target THEN
                UPDATE public.delivery_schedule_lines SET status = target WHERE tenant_id = NEW.tenant_id AND id = NEW.schedule_line_id;
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_follow_delivered() FROM PUBLIC;
            CREATE TRIGGER follow_delivered AFTER INSERT ON inventory_movements FOR EACH ROW WHEN (NEW.schedule_line_id IS NOT NULL) EXECUTE FUNCTION eora_follow_delivered();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS follow_delivered ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_follow_delivered();
            DROP TRIGGER IF EXISTS check_shipment ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_shipment();
            DROP TRIGGER IF EXISTS guard_shipment ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_shipment_document();
            DROP TABLE shipment_details;
            DROP FUNCTION IF EXISTS eora_guard_shipment_details();
            SQL);
    }
};

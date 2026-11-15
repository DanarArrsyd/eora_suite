<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M5 step 1 (doc 34 §1.1, D-121, D-087, G-MPS-2/4): planning parameters per item + plant and the supplier inbound
 * schedule.
 *
 * - `item_plan_parameters`: production lead time (made items) and purchase lead time (bought items) in plant working
 *   days, safety stock in the item's base unit (NULL = NOT CONFIGURED), lot policy lot-for-lot. PPC changes them
 *   directly (`planning.plan_parameter.create`), audited, no approval.
 * - `supplier_inbound_lines`: reference data (no price, approval or invoice) entered by MAT or PPC
 *   (`materials.inbound_schedule.create`): supplier, item, plant, quantity, expected date; edited while nothing was
 *   received against it, cancelled with a reason.
 * - `inbound_receipts`: an RM receipt names the inbound line it received against (same plant, supplier and item), written
 *   with its posting. Received quantity follows the posted receipts; a reversed receipt no longer counts.
 *
 * Existing tenants receive the template permissions through `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, module, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['planning.plan_parameter.view', 'planning', 'view', false, ['PPC', 'CSA', 'MAT', 'PRL', 'INV', 'AUD']],
        ['planning.plan_parameter.create', 'planning', 'create', false, ['PPC']],
        ['materials.inbound_schedule.view', 'materials', 'view', false, ['MAT', 'PPC', 'CSA', 'INV', 'AUD']],
        ['materials.inbound_schedule.create', 'materials', 'create', false, ['MAT', 'PPC']],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$code, $module, $action, $sensitive, $templates]) {
            DB::insert('insert into permissions (code, module, action, sensitive) values (?, ?, ?, ?) on conflict (code) do nothing', [$code, $module, $action, $sensitive]);
            foreach ($templates as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE item_plan_parameters (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              item_id uuid NOT NULL,
              production_lead_time_days integer CHECK (production_lead_time_days BETWEEN 0 AND 365),
              purchase_lead_time_days integer CHECK (purchase_lead_time_days BETWEEN 0 AND 365),
              -- NULL = NOT CONFIGURED (counted as 0 and shown, D-046 G-MPS-2)
              safety_stock numeric(20, 6) CHECK (safety_stock >= 0),
              lot_policy text NOT NULL DEFAULT 'LOT_FOR_LOT' CHECK (lot_policy IN ('LOT_FOR_LOT')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, item_id),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON item_plan_parameters FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE item_plan_parameters ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON item_plan_parameters TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON item_plan_parameters TO eora_app;

            CREATE OR REPLACE FUNCTION eora_guard_item_plan_parameters() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT public.eora_actor_may('planning.plan_parameter.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'planning parameters are changed by a planner of the plant' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'UPDATE' AND (NEW.plant_id, NEW.item_id) IS DISTINCT FROM (OLD.plant_id, OLD.item_id) THEN
                RAISE EXCEPTION 'planning parameters keep their item and plant' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_item_plan_parameters() FROM PUBLIC;
            CREATE TRIGGER guard_item_plan_parameters BEFORE INSERT OR UPDATE ON item_plan_parameters FOR EACH ROW EXECUTE FUNCTION eora_guard_item_plan_parameters();

            CREATE TABLE supplier_inbound_lines (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              inbound_no text NOT NULL CHECK (inbound_no ~ '^INB-[A-Z0-9_-]+-[0-9]{4}-[0-9]{4,}$'),
              supplier_id uuid NOT NULL,
              item_id uuid NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              expected_date date NOT NULL,
              note text CHECK (length(note) BETWEEN 1 AND 500),
              cancelled_at timestamptz,
              cancelled_by uuid,
              cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, inbound_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, supplier_id) REFERENCES suppliers (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              CHECK ((cancelled_at IS NULL) = (cancelled_by IS NULL) AND (cancelled_at IS NULL) = (cancel_reason IS NULL))
            );
            CREATE INDEX supplier_inbound_lines_open_idx ON supplier_inbound_lines (tenant_id, plant_id, item_id, expected_date) WHERE cancelled_at IS NULL;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON supplier_inbound_lines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE supplier_inbound_lines ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON supplier_inbound_lines TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON supplier_inbound_lines TO eora_app;

            -- the inbound line a posted RM receipt received against (D-121), written with the posting
            CREATE TABLE inbound_receipts (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              document_id uuid NOT NULL,
              inbound_line_id uuid NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, document_id),
              FOREIGN KEY (tenant_id, document_id) REFERENCES inventory_documents (tenant_id, id),
              FOREIGN KEY (tenant_id, inbound_line_id) REFERENCES supplier_inbound_lines (tenant_id, id)
            );
            CREATE INDEX inbound_receipts_line_idx ON inbound_receipts (tenant_id, inbound_line_id);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON inbound_receipts FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE inbound_receipts ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON inbound_receipts TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON inbound_receipts TO eora_app;

            -- who writes the schedule, and what a line keeps once something was received against it
            CREATE OR REPLACE FUNCTION eora_guard_supplier_inbound_lines() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF NOT public.eora_actor_may('materials.inbound_schedule.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'the inbound schedule is written by materials or a planner of the plant' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.cancelled_at IS NOT NULL THEN
                  RAISE EXCEPTION 'an inbound line starts open' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.plant_id, NEW.inbound_no, NEW.supplier_id, NEW.item_id) IS DISTINCT FROM (OLD.plant_id, OLD.inbound_no, OLD.supplier_id, OLD.item_id) THEN
                RAISE EXCEPTION 'an inbound line keeps its number, plant, supplier and item' USING ERRCODE = '23514';
              END IF;
              IF OLD.cancelled_at IS NOT NULL THEN
                RAISE EXCEPTION 'a cancelled inbound line is history' USING ERRCODE = '23514';
              END IF;
              IF NEW.cancelled_at IS NOT NULL AND (NEW.cancelled_by IS DISTINCT FROM a OR (NEW.qty, NEW.expected_date) IS DISTINCT FROM (OLD.qty, OLD.expected_date)) THEN
                RAISE EXCEPTION 'a cancel only closes the line, by who cancels it' USING ERRCODE = '23514';
              END IF;
              IF (NEW.qty, NEW.expected_date) IS DISTINCT FROM (OLD.qty, OLD.expected_date)
                 AND EXISTS (SELECT 1 FROM public.inbound_receipts r JOIN public.inventory_documents d ON d.tenant_id = r.tenant_id AND d.id = r.document_id
                              WHERE r.tenant_id = NEW.tenant_id AND r.inbound_line_id = NEW.id AND d.status = 'POSTED') THEN
                RAISE EXCEPTION 'an inbound line with receipts keeps its quantity and date' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_supplier_inbound_lines() FROM PUBLIC;
            CREATE TRIGGER guard_supplier_inbound_lines BEFORE INSERT OR UPDATE ON supplier_inbound_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_supplier_inbound_lines();

            -- a receipt names an open line of its plant, supplier and item, with its posting
            CREATE OR REPLACE FUNCTION eora_guard_inbound_receipts() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              l record;
            BEGIN
              -- the share lock waits for a cancel in flight and reads the line as it committed
              SELECT x.plant_id, x.supplier_id, x.item_id, x.cancelled_at INTO l
                FROM public.supplier_inbound_lines x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.inbound_line_id FOR SHARE;
              IF l.cancelled_at IS NOT NULL OR l.plant_id IS NULL OR NOT EXISTS (
                SELECT 1 FROM public.inventory_documents d
                 WHERE d.tenant_id = NEW.tenant_id AND d.id = NEW.document_id AND d.doc_type = 'RM_RECEIPT' AND d.status = 'POSTED'
                   AND d.posted_by = (SELECT c.actor FROM ctx_api.context() c) AND d.posted_at = pg_catalog.now()
                   AND l.plant_id = d.plant_id AND d.source_type = 'supplier' AND l.supplier_id = d.source_id
                   AND EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = d.tenant_id AND m.document_id = d.id AND m.item_id = l.item_id)) THEN
                RAISE EXCEPTION 'a receipt names an open inbound line of its plant, supplier and item, with its posting' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_inbound_receipts() FROM PUBLIC;
            CREATE TRIGGER guard_inbound_receipts BEFORE INSERT ON inbound_receipts FOR EACH ROW EXECUTE FUNCTION eora_guard_inbound_receipts();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS inbound_receipts;
            DROP FUNCTION IF EXISTS eora_guard_inbound_receipts();
            DROP TABLE IF EXISTS supplier_inbound_lines;
            DROP FUNCTION IF EXISTS eora_guard_supplier_inbound_lines();
            DROP TABLE IF EXISTS item_plan_parameters;
            DROP FUNCTION IF EXISTS eora_guard_item_plan_parameters();
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
};

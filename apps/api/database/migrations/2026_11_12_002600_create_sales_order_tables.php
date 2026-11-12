<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 1 (doc 33 §1.1, D-107): Sales Orders — header, lines and Delivery Schedule Lines (doc 17 §C, D-034,
 * BR-DEM-05/06/10, doc 16 §12). Owner rules of 2026-11-12:
 * - the customer PO is a reference on the SO line (number + line); one active SO line per customer PO line (BR-DEM-05);
 * - the SO line quantity is the sum of its schedule lines (no separate ordered quantity);
 * - a confirmed (FIRM) schedule line never changes date or quantity: lines are added or cancelled, history stays;
 * - drafts are cancelled, never deleted; numbers are never reused (D-049, `document_counters`).
 * Rows are written only while their order is a draft, except the status / cancel columns of the lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sales_order_headers (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              customer_id uuid NOT NULL,
              so_no text NOT NULL CHECK (so_no ~ '^[A-Z0-9][A-Z0-9/_-]{0,39}$'),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'CONFIRMED', 'CANCELLED')),
              note text CHECK (length(note) BETWEEN 1 AND 500 AND note = btrim(note)),
              confirmed_at timestamptz,
              confirmed_by uuid,
              cancelled_at timestamptz,
              cancelled_by uuid,
              cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 500 AND cancel_reason = btrim(cancel_reason)),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, customer_id, plant_id),
              UNIQUE (tenant_id, so_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, customer_id) REFERENCES customers (tenant_id, id),
              CHECK ((status = 'CONFIRMED') = (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL) OR status = 'CANCELLED'),
              CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))
            );
            CREATE INDEX sales_order_headers_list_idx ON sales_order_headers (tenant_id, plant_id, created_at DESC);

            CREATE TABLE sales_order_lines (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              sales_order_id uuid NOT NULL,
              customer_id uuid NOT NULL,
              plant_id uuid NOT NULL,
              line_no integer NOT NULL CHECK (line_no BETWEEN 1 AND 9999),
              -- the one demand identity of this line (D-034, BR-DEM-05)
              demand_id uuid NOT NULL DEFAULT uuidv7(),
              customer_item_id uuid NOT NULL,
              item_id uuid NOT NULL,
              uom_id uuid NOT NULL,
              customer_po_no text NOT NULL CHECK (length(customer_po_no) BETWEEN 1 AND 40 AND customer_po_no = btrim(customer_po_no)),
              customer_po_line text NOT NULL CHECK (length(customer_po_line) BETWEEN 1 AND 20 AND customer_po_line = btrim(customer_po_line)),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'CANCELLED')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, uom_id, plant_id),
              UNIQUE (tenant_id, demand_id),
              UNIQUE (tenant_id, sales_order_id, line_no),
              FOREIGN KEY (tenant_id, sales_order_id, customer_id, plant_id) REFERENCES sales_order_headers (tenant_id, id, customer_id, plant_id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, customer_item_id) REFERENCES customer_items (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id)
            );
            -- BR-DEM-05: a customer PO line maps to exactly one active SO line (PO number and line compared without case)
            CREATE UNIQUE INDEX sales_order_lines_customer_po_uq ON sales_order_lines (tenant_id, customer_id, upper(customer_po_no), upper(customer_po_line))
              WHERE status = 'ACTIVE';
            CREATE INDEX sales_order_lines_item_idx ON sales_order_lines (tenant_id, item_id);

            CREATE TABLE delivery_schedule_lines (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              so_line_id uuid NOT NULL,
              plant_id uuid NOT NULL,
              schedule_no integer NOT NULL CHECK (schedule_no BETWEEN 1 AND 9999),
              schedule_type text NOT NULL DEFAULT 'MANUAL' CHECK (schedule_type IN ('CUSTOMER_SCHEDULE', 'KANBAN_RELEASE', 'MANUAL')),
              source_document_type text NOT NULL CHECK (source_document_type IN ('SALES_ORDER', 'CUSTOMER_PO', 'CUSTOMER_SCHEDULE')),
              source_document_no text NOT NULL CHECK (length(source_document_no) BETWEEN 1 AND 40),
              source_line_ref text NOT NULL CHECK (length(source_line_ref) BETWEEN 1 AND 40),
              requirement_date date NOT NULL,
              required_qty numeric(20, 6) NOT NULL CHECK (required_qty > 0),
              uom_id uuid NOT NULL,
              revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'FIRM', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CANCELLED', 'SUPERSEDED')),
              superseded_by uuid,
              cancelled_at timestamptz,
              cancelled_by uuid,
              cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 500 AND cancel_reason = btrim(cancel_reason)),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, so_line_id, schedule_no),
              -- BR-DEM-06: one schedule line per source line and revision (a re-import is never a second demand)
              UNIQUE (tenant_id, so_line_id, source_document_no, source_line_ref, revision),
              -- the schedule line is in the SO line's unit and plant
              FOREIGN KEY (tenant_id, so_line_id, uom_id, plant_id) REFERENCES sales_order_lines (tenant_id, id, uom_id, plant_id) ON DELETE CASCADE,
              CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))
            );
            CREATE INDEX delivery_schedule_lines_due_idx ON delivery_schedule_lines (tenant_id, plant_id, requirement_date)
              WHERE status IN ('FIRM', 'PARTIALLY_FULFILLED');

            -- lines and schedule lines are written (inserted, replaced, deleted) only while their order is a draft; a
            -- confirmed order changes only through new FIRM rows and the cancel columns (owner, 2026-11-12)
            CREATE OR REPLACE FUNCTION eora_guard_sales_order_child() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              header_status text;
              row_status text := CASE WHEN TG_OP = 'DELETE' THEN OLD.status ELSE NEW.status END;
            BEGIN
              IF TG_TABLE_NAME = 'sales_order_lines' THEN
                SELECT h.status INTO header_status FROM public.sales_order_headers h
                 WHERE h.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND h.id = COALESCE(NEW.sales_order_id, OLD.sales_order_id);
              ELSE
                SELECT h.status INTO header_status FROM public.sales_order_lines l
                  JOIN public.sales_order_headers h ON h.tenant_id = l.tenant_id AND h.id = l.sales_order_id
                 WHERE l.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND l.id = COALESCE(NEW.so_line_id, OLD.so_line_id);
              END IF;
              IF header_status IS NULL THEN
                RETURN COALESCE(NEW, OLD); -- the order itself is going (cascade of a header delete: none are granted)
              END IF;
              IF TG_OP = 'DELETE' THEN
                IF header_status <> 'DRAFT' THEN
                  RAISE EXCEPTION 'only a draft order loses lines; cancel them instead' USING ERRCODE = '23514';
                END IF;
                RETURN OLD;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF header_status = 'CANCELLED' THEN
                  RAISE EXCEPTION 'a cancelled order takes no lines' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'delivery_schedule_lines' AND (header_status = 'DRAFT') <> (row_status = 'DRAFT') THEN
                  RAISE EXCEPTION 'a draft order has draft schedule lines, a confirmed order firm ones' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              -- UPDATE: what a line asks for never changes in place (a change is a cancel and a new line)
              IF TG_TABLE_NAME = 'delivery_schedule_lines' THEN
                IF (NEW.requirement_date, NEW.required_qty, NEW.uom_id, NEW.so_line_id, NEW.schedule_no, NEW.source_document_no, NEW.source_line_ref, NEW.revision)
                   IS DISTINCT FROM (OLD.requirement_date, OLD.required_qty, OLD.uom_id, OLD.so_line_id, OLD.schedule_no, OLD.source_document_no, OLD.source_line_ref, OLD.revision) THEN
                  RAISE EXCEPTION 'a schedule line keeps its date and quantity; cancel it and add a new one' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('CANCELLED', 'SUPERSEDED') THEN
                  RAISE EXCEPTION 'a cancelled or superseded schedule line is history' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'CANCELLED' AND OLD.status NOT IN ('DRAFT', 'FIRM') THEN
                  RAISE EXCEPTION 'a schedule line with deliveries cannot be cancelled' USING ERRCODE = '23514';
                END IF;
              ELSIF (NEW.customer_item_id, NEW.item_id, NEW.uom_id, NEW.customer_po_no, NEW.customer_po_line, NEW.line_no, NEW.demand_id)
                    IS DISTINCT FROM (OLD.customer_item_id, OLD.item_id, OLD.uom_id, OLD.customer_po_no, OLD.customer_po_line, OLD.line_no, OLD.demand_id) THEN
                RAISE EXCEPTION 'an order line keeps its item and customer PO reference' USING ERRCODE = '23514';
              ELSIF OLD.status = 'CANCELLED' AND NEW.status <> 'CANCELLED' THEN
                RAISE EXCEPTION 'a cancelled order line stays cancelled' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_sales_order_child() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE OR DELETE ON sales_order_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_sales_order_child();
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE OR DELETE ON delivery_schedule_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_sales_order_child();

            -- the header: DRAFT → CONFIRMED (by the signed actor) or DRAFT → CANCELLED; customer, plant and number never change
            CREATE OR REPLACE FUNCTION eora_guard_sales_order_header() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' THEN
                  RAISE EXCEPTION 'an order starts as a draft' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.plant_id, NEW.customer_id, NEW.so_no) IS DISTINCT FROM (OLD.plant_id, OLD.customer_id, OLD.so_no) THEN
                RAISE EXCEPTION 'an order keeps its plant, customer and number' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'CANCELLED' THEN
                RAISE EXCEPTION 'a cancelled order is history' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                IF OLD.status = 'DRAFT' AND NEW.status = 'CONFIRMED' THEN
                  IF NEW.confirmed_by IS DISTINCT FROM a THEN
                    RAISE EXCEPTION 'an order is confirmed by the signed actor' USING ERRCODE = '42501';
                  END IF;
                ELSIF OLD.status = 'DRAFT' AND NEW.status = 'CANCELLED' THEN
                  IF NEW.cancelled_by IS DISTINCT FROM a THEN
                    RAISE EXCEPTION 'an order is cancelled by the signed actor' USING ERRCODE = '42501';
                  END IF;
                ELSE
                  RAISE EXCEPTION 'an order goes DRAFT → CONFIRMED or DRAFT → CANCELLED' USING ERRCODE = '23514';
                END IF;
              ELSIF (NEW.confirmed_at, NEW.confirmed_by) IS DISTINCT FROM (OLD.confirmed_at, OLD.confirmed_by) THEN
                RAISE EXCEPTION 'a confirmation is history' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_sales_order_header() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON sales_order_headers FOR EACH ROW EXECUTE FUNCTION eora_guard_sales_order_header();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['sales_order_headers', 'sales_order_lines', 'delivery_schedule_lines'] LOOP
                EXECUTE format('CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit()', t);
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            -- a header is never deleted; `note` doubles as the version bump when only its lines change
            GRANT UPDATE (status, note, confirmed_at, confirmed_by, cancelled_at, cancelled_by, cancel_reason) ON sales_order_headers TO eora_app;
            GRANT UPDATE (status), DELETE ON sales_order_lines TO eora_app;
            GRANT UPDATE (status, cancelled_at, cancelled_by, cancel_reason), DELETE ON delivery_schedule_lines TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE delivery_schedule_lines;
            DROP TABLE sales_order_lines;
            DROP TABLE sales_order_headers;
            DROP FUNCTION IF EXISTS eora_guard_sales_order_child();
            DROP FUNCTION IF EXISTS eora_guard_sales_order_header();
            SQL);
    }
};

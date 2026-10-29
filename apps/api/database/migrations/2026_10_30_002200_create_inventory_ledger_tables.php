<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M3 step 1 (doc 32 §1.1, D-096; ADR-0002, ADR-0014, doc 04 §Inventory ledger posting specification, doc 17 §H):
 * the ledger tables. Rows are written by `InventoryPostingService` (step 2) only; this migration fixes what the
 * database itself guarantees:
 *
 * - `lots`: one lot of one item; internal `lot_no` (M3-D3) unique per item, supplier lot/heat and mill-cert properties
 *   optional; a split lot (child) belongs to the parent's item. Items without lot control have no lots (M3-D4).
 * - `inventory_documents`: one business document per plant. DRAFT/SUBMITTED documents carry the request (adjustment,
 *   reversal: approved by a second person, D-060); POSTED documents have their official number (D-049), dates (D-044)
 *   and never change again, except POSTED → REVERSED when a reversal posts. A document is reversed at most once.
 * - `inventory_movements`: the legs — append-only (no UPDATE/DELETE for any login role), signed quantity ≠ 0, movement
 *   code from the system catalogue (D-084), the entered quantity and unit when posted in another unit (M3-D5).
 * - `stock_balances`: the projection, one row per balance key (lot NULL for items without lot control, ownership OWN
 *   in V1, M3-D9), quantity never negative, physical locations only (BR-INV-04, ADR-0002 §3).
 * - `stock_allocations` (row 2, used from M4), `genealogy_edges`, `inventory_periods` (monthly per plant, M3-D8),
 *   `document_counters` (D-049) and `lot_counters` (M3-D3), `idempotency_records` (ADR-0014 §4).
 *
 * Every table: forced RLS on the signed tenant context, composite tenant FKs, actor/time stamped by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lots (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              lot_no text NOT NULL CHECK (lot_no ~ '^[A-Z0-9][A-Z0-9._/-]{0,39}$'),
              supplier_lot_no text CHECK (length(supplier_lot_no) BETWEEN 1 AND 60),
              origin text NOT NULL CHECK (origin IN ('RECEIPT', 'OUTPUT', 'SPLIT')),
              parent_lot_id uuid,
              -- measured or certified properties (mill cert thickness/width/weight …), codes and numbers only
              properties jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(properties) = 'object' AND pg_column_size(properties) <= 4096),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, item_id, id),
              UNIQUE (tenant_id, item_id, lot_no),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id, parent_lot_id) REFERENCES lots (tenant_id, item_id, id),
              CHECK ((origin = 'SPLIT') = (parent_lot_id IS NOT NULL)),
              CHECK (parent_lot_id IS DISTINCT FROM id)
            );
            CREATE INDEX lots_parent_idx ON lots (tenant_id, parent_lot_id) WHERE parent_lot_id IS NOT NULL;

            -- next internal lot number per item and month (M3-D3: L<YYMM>-<n>)
            CREATE TABLE lot_counters (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              item_id uuid NOT NULL,
              period text NOT NULL CHECK (period ~ '^[0-9]{4}$'),
              last_no integer NOT NULL CHECK (last_no > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, item_id, period),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );

            CREATE TABLE inventory_periods (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              period date NOT NULL CHECK (extract(day FROM period) = 1),
              status text NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSING', 'CLOSED')),
              close_requested_by uuid,
              close_requested_at timestamptz,
              closed_by uuid,
              closed_at timestamptz,
              reopen_reason text CHECK (length(reopen_reason) BETWEEN 3 AND 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, period),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL)),
              CHECK (status = 'OPEN' OR close_requested_at IS NOT NULL)
            );

            -- official document numbers per tenant + plant + type + year (D-049: unique, never reused, no gapless promise)
            CREATE TABLE document_counters (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              plant_id uuid NOT NULL,
              doc_type text NOT NULL,
              year integer NOT NULL CHECK (year BETWEEN 2000 AND 2999),
              last_no integer NOT NULL CHECK (last_no > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, plant_id, doc_type, year),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );

            CREATE TABLE inventory_documents (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              doc_type text NOT NULL CHECK (doc_type IN ('RM_RECEIPT', 'INCOMING_RELEASE', 'MAT_ISSUE', 'CUSTODY_ACCEPT', 'MAT_TRANSFER', 'MAT_RETURN',
                'LOT_SPLIT', 'PRODUCTION_POSTING', 'QC_DISPOSITION', 'FG_RECEIPT', 'PICK', 'SHIPMENT', 'SCRAP_DISPOSITION', 'REWORK_POSTING',
                'STOCK_ADJUSTMENT', 'REVERSAL')),
              doc_no text CHECK (doc_no ~ '^[A-Z0-9][A-Z0-9/_-]{0,39}$'),
              status text NOT NULL CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'POSTED', 'REVERSED')),
              -- business dates (D-044): the day the event belongs to, its production date and shift, the local calendar date
              effective_date date NOT NULL,
              production_date date,
              calendar_date date,
              shift_id uuid,
              shift_date date,
              posted_at timestamptz,
              posted_by uuid,
              -- requests decided by a second person (adjustment, reversal: D-060)
              requested_by uuid,
              submitted_at timestamptz,
              decided_by uuid,
              decided_at timestamptz,
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              reason_code text CHECK (reason_code ~ '^[A-Z][A-Z0-9_]{1,39}$'),
              note text CHECK (length(note) <= 1000),
              request jsonb CHECK (request IS NULL OR (jsonb_typeof(request) = 'object' AND pg_column_size(request) <= 65536)),
              reverses_document_id uuid,
              correction_of_document_id uuid,
              original_effective_date date,
              source_type text CHECK (source_type ~ '^[a-z][a-z_]{1,39}$'),
              source_id uuid,
              idempotency_key text NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 200),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, idempotency_key),
              UNIQUE (tenant_id, plant_id, doc_type, doc_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, plant_id, reverses_document_id) REFERENCES inventory_documents (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, plant_id, correction_of_document_id) REFERENCES inventory_documents (tenant_id, plant_id, id),
              CHECK ((status IN ('POSTED', 'REVERSED')) = (doc_no IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL
                                                           AND production_date IS NOT NULL AND calendar_date IS NOT NULL)),
              CHECK ((doc_type = 'REVERSAL') = (reverses_document_id IS NOT NULL)),
              CHECK ((correction_of_document_id IS NULL) = (original_effective_date IS NULL)),
              CHECK (status <> 'REJECTED' OR (reject_reason IS NOT NULL AND decided_by IS NOT NULL)),
              CHECK ((source_type IS NULL) = (source_id IS NULL))
            );
            -- a document is reversed at most once (a rejected reversal request does not count)
            CREATE UNIQUE INDEX inventory_documents_reversal_uq ON inventory_documents (tenant_id, reverses_document_id)
              WHERE reverses_document_id IS NOT NULL AND status <> 'REJECTED';
            CREATE INDEX inventory_documents_list_idx ON inventory_documents (tenant_id, plant_id, effective_date DESC, id);
            CREATE INDEX inventory_documents_pending_idx ON inventory_documents (tenant_id, status) WHERE status IN ('DRAFT', 'SUBMITTED');

            CREATE OR REPLACE FUNCTION eora_guard_inventory_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF (NEW.tenant_id, NEW.plant_id, NEW.doc_type, NEW.idempotency_key, NEW.reverses_document_id, NEW.correction_of_document_id)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.doc_type, OLD.idempotency_key, OLD.reverses_document_id, OLD.correction_of_document_id) THEN
                RAISE EXCEPTION 'inventory document identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('POSTED', 'REVERSED', 'REJECTED') THEN
                -- a posted document changes only once more: POSTED → REVERSED, nothing else
                IF NOT (OLD.status = 'POSTED' AND NEW.status = 'REVERSED'
                        AND (NEW.doc_no, NEW.effective_date, NEW.production_date, NEW.calendar_date, NEW.shift_id, NEW.shift_date,
                             NEW.posted_at, NEW.posted_by, NEW.request, NEW.reason_code, NEW.note)
                            IS NOT DISTINCT FROM (OLD.doc_no, OLD.effective_date, OLD.production_date, OLD.calendar_date, OLD.shift_id, OLD.shift_date,
                                                  OLD.posted_at, OLD.posted_by, OLD.request, OLD.reason_code, OLD.note)) THEN
                  RAISE EXCEPTION 'a % inventory document never changes', lower(OLD.status) USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_inventory_document() FROM PUBLIC;
            CREATE TRIGGER guard_posted BEFORE UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_inventory_document();

            CREATE TABLE inventory_movements (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              document_id uuid NOT NULL,
              leg_no integer NOT NULL CHECK (leg_no > 0),
              movement_code text NOT NULL CHECK (movement_code IN ('RCPT_SUPPLIER', 'INCOMING_RELEASE', 'ISSUE_TO_PROD', 'CUSTODY_ACCEPT',
                'TRANSFER_LOC', 'TRANSFER_PLANT_OUT', 'TRANSFER_PLANT_IN', 'CONSUME', 'RETURN_FROM_PROD', 'LOT_SPLIT_OUT', 'LOT_SPLIT_IN',
                'OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT', 'OUTPUT_SCRAP', 'OUTPUT_OTHER', 'QC_RELEASE', 'QC_HOLD', 'QC_REWORK',
                'QC_REJECT', 'REWORK_IN', 'REWORK_OUT', 'FG_RECEIPT', 'PICK', 'SHIP', 'ADJ_PLUS', 'ADJ_MINUS', 'SCRAP_DISPOSAL')),
              reverses_movement_id uuid,
              plant_id uuid NOT NULL,
              location_id uuid NOT NULL,
              item_id uuid NOT NULL,
              lot_id uuid,
              stock_status text NOT NULL CHECK (stock_status IN ('UNRESTRICTED', 'PENDING_QC', 'QC_RELEASED', 'HOLD', 'REWORK', 'REJECTED', 'BLOCKED')),
              ownership text NOT NULL DEFAULT 'OWN' CHECK (ownership IN ('OWN', 'CUSTOMER_OWNED', 'CONSIGNMENT')),
              uom_id uuid NOT NULL,
              qty_signed numeric(20, 6) NOT NULL CHECK (qty_signed <> 0),
              -- the quantity as entered when another unit was used (M3-D5); the conversion version that turned it into qty_signed
              entered_qty numeric(20, 6) CHECK (entered_qty > 0),
              entered_uom_id uuid,
              uom_conversion_id uuid,
              measurement_method text CHECK (measurement_method IN ('WEIGHED', 'COUNTED', 'METERED', 'BY_DIFFERENCE', 'THEORETICAL')),
              run_id uuid,
              wo_id uuid,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, document_id, leg_no),
              FOREIGN KEY (tenant_id, plant_id, document_id) REFERENCES inventory_documents (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, plant_id, location_id) REFERENCES locations (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id, lot_id) REFERENCES lots (tenant_id, item_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, entered_uom_id) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, reverses_movement_id) REFERENCES inventory_movements (tenant_id, id),
              CHECK ((entered_qty IS NULL) = (entered_uom_id IS NULL)),
              CHECK (uom_conversion_id IS NULL OR entered_qty IS NOT NULL)
            );
            CREATE INDEX inventory_movements_lot_idx ON inventory_movements (tenant_id, lot_id, id) WHERE lot_id IS NOT NULL;
            CREATE INDEX inventory_movements_document_idx ON inventory_movements (tenant_id, document_id);
            CREATE INDEX inventory_movements_place_idx ON inventory_movements (tenant_id, plant_id, location_id, item_id);
            CREATE UNIQUE INDEX inventory_movements_reversed_uq ON inventory_movements (tenant_id, reverses_movement_id) WHERE reverses_movement_id IS NOT NULL;

            -- the projection; lot NULL = item without lot control (M3-D4)
            CREATE TABLE stock_balances (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              location_id uuid NOT NULL,
              item_id uuid NOT NULL,
              lot_id uuid,
              stock_status text NOT NULL CHECK (stock_status IN ('UNRESTRICTED', 'PENDING_QC', 'QC_RELEASED', 'HOLD', 'REWORK', 'REJECTED', 'BLOCKED')),
              ownership text NOT NULL DEFAULT 'OWN' CHECK (ownership IN ('OWN', 'CUSTOMER_OWNED', 'CONSIGNMENT')),
              uom_id uuid NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty >= 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE NULLS NOT DISTINCT (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, ownership, uom_id),
              FOREIGN KEY (tenant_id, plant_id, location_id) REFERENCES locations (tenant_id, plant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id, lot_id) REFERENCES lots (tenant_id, item_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id)
            );
            CREATE INDEX stock_balances_item_idx ON stock_balances (tenant_id, plant_id, item_id) WHERE qty > 0;

            -- virtual locations are ledger counterparties and never hold a balance (ADR-0002 §3)
            CREATE OR REPLACE FUNCTION eora_guard_physical_balance() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF EXISTS (SELECT 1 FROM public.locations l WHERE l.id = NEW.location_id AND l.location_type LIKE 'V\_%') THEN
                RAISE EXCEPTION 'a virtual location holds no balance' USING ERRCODE = '23514';
              END IF;
              IF TG_OP = 'UPDATE' AND (NEW.tenant_id, NEW.plant_id, NEW.location_id, NEW.item_id, NEW.lot_id, NEW.stock_status, NEW.ownership, NEW.uom_id)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.location_id, OLD.item_id, OLD.lot_id, OLD.stock_status, OLD.ownership, OLD.uom_id) THEN
                RAISE EXCEPTION 'a balance key is fixed' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_physical_balance() FROM PUBLIC;
            CREATE TRIGGER guard_physical BEFORE INSERT OR UPDATE ON stock_balances FOR EACH ROW EXECUTE FUNCTION eora_guard_physical_balance();

            -- matrix row 2 (BR-INV-08): reduces available, never on-hand; Σ active ≤ on-hand is checked by the service under
            -- the balance lock. Used from M4 (WO, schedule lines).
            CREATE TABLE stock_allocations (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              balance_id uuid NOT NULL,
              demand_type text NOT NULL CHECK (demand_type IN ('WO', 'SCHEDULE_LINE')),
              demand_id uuid NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'CONSUMED', 'RELEASED')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, balance_id) REFERENCES stock_balances (tenant_id, id)
            );
            CREATE INDEX stock_allocations_demand_idx ON stock_allocations (tenant_id, demand_type, demand_id) WHERE status = 'ACTIVE';
            CREATE INDEX stock_allocations_balance_idx ON stock_allocations (tenant_id, balance_id) WHERE status = 'ACTIVE';

            CREATE TABLE genealogy_edges (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              from_lot_id uuid NOT NULL,
              to_lot_id uuid NOT NULL,
              document_id uuid NOT NULL,
              run_id uuid,
              qty_from numeric(20, 6) NOT NULL CHECK (qty_from > 0),
              uom_from uuid NOT NULL,
              qty_to numeric(20, 6) NOT NULL CHECK (qty_to > 0),
              uom_to uuid NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, from_lot_id) REFERENCES lots (tenant_id, id),
              FOREIGN KEY (tenant_id, to_lot_id) REFERENCES lots (tenant_id, id),
              FOREIGN KEY (tenant_id, document_id) REFERENCES inventory_documents (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_from) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_to) REFERENCES uoms (tenant_id, id),
              CHECK (from_lot_id <> to_lot_id)
            );
            CREATE INDEX genealogy_edges_from_idx ON genealogy_edges (tenant_id, from_lot_id);
            CREATE INDEX genealogy_edges_to_idx ON genealogy_edges (tenant_id, to_lot_id);

            -- ADR-0014 §4: a command replayed with its key returns the stored response; another payload under the key → 409
            CREATE TABLE idempotency_records (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              idempotency_key text NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 200),
              command text NOT NULL CHECK (command ~ '^[a-z][a-z_.]{2,79}$'),
              request_hash text NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
              response jsonb NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, idempotency_key)
            );

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['lots', 'lot_counters', 'inventory_periods', 'document_counters', 'inventory_documents', 'inventory_movements',
                                       'stock_balances', 'stock_allocations', 'genealogy_edges', 'idempotency_records'] LOOP
                EXECUTE format('CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit()', t);
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            -- append-only ledger (BR-INV-02): movements and genealogy have no UPDATE/DELETE grant at all; the rest change
            -- only the columns their lifecycle needs
            GRANT UPDATE (supplier_lot_no, properties) ON lots TO eora_app;
            GRANT UPDATE (last_no) ON lot_counters, document_counters TO eora_app;
            GRANT UPDATE (status, close_requested_by, close_requested_at, closed_by, closed_at, reopen_reason) ON inventory_periods TO eora_app;
            GRANT UPDATE (doc_no, status, effective_date, production_date, calendar_date, shift_id, shift_date, posted_at, posted_by,
                          requested_by, submitted_at, decided_by, decided_at, reject_reason, reason_code, note, request,
                          original_effective_date, source_type, source_id) ON inventory_documents TO eora_app;
            GRANT UPDATE (qty) ON stock_balances TO eora_app;
            GRANT UPDATE (status) ON stock_allocations TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE idempotency_records;
            DROP TABLE genealogy_edges;
            DROP TABLE stock_allocations;
            DROP TABLE stock_balances;
            DROP TABLE inventory_movements;
            DROP TABLE inventory_documents;
            DROP TABLE document_counters;
            DROP TABLE inventory_periods;
            DROP TABLE lot_counters;
            DROP TABLE lots;
            DROP FUNCTION eora_guard_physical_balance();
            DROP FUNCTION eora_guard_inventory_document();
            SQL);
    }
};

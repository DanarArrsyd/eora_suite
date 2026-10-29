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
 * - `inventory_documents`: one business document per plant. Adjustments, reversals and closed-period corrections start
 *   as a request of the signed actor and are decided by another person (D-060), enforced by trigger; POSTED documents
 *   have their official number (D-049), dates (D-044) and legs, and never change again except POSTED → REVERSED once
 *   their reversal is posted. A document is reversed at most once; a reversal negates every leg once (checked at commit).
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
              -- BR-PER-03: a closed period reopens only on a request decided by a second person (the last reopen is kept)
              reopen_requested_by uuid,
              reopen_requested_at timestamptz,
              reopen_reason text CHECK (length(reopen_reason) BETWEEN 3 AND 500),
              reopened_by uuid,
              reopened_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, period),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL AND closed_by IS NOT NULL)),
              CHECK ((status = 'OPEN') = (close_requested_at IS NULL AND close_requested_by IS NULL)),
              CHECK ((reopen_requested_at IS NULL) = (reopen_requested_by IS NULL) AND (reopen_requested_at IS NULL) = (reopen_reason IS NULL)),
              CHECK (reopen_requested_at IS NULL OR status = 'CLOSED')
            );

            -- M3-D8, BR-PER-03, D-060: a period is opened, closed on a request decided by a second person, and reopened the
            -- same way; every actor is the signed actor of the statement that records it
            CREATE OR REPLACE FUNCTION eora_guard_inventory_period() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'OPEN' OR NEW.closed_by IS NOT NULL OR NEW.reopened_by IS NOT NULL OR NEW.reopen_requested_by IS NOT NULL THEN
                  RAISE EXCEPTION 'a period starts open' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.plant_id, NEW.period) IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.period) THEN
                RAISE EXCEPTION 'a period is fixed' USING ERRCODE = '23514';
              END IF;
              -- every update is one of the transitions below; each pins the history it does not write
              IF OLD.status = 'OPEN' AND NEW.status = 'CLOSING' THEN
                IF NEW.close_requested_by IS DISTINCT FROM a OR NEW.close_requested_at IS NULL
                   OR (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a close is requested by the signed actor' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'CLOSED' THEN
                IF NEW.closed_by IS DISTINCT FROM a OR a = OLD.close_requested_by OR NEW.closed_at IS NULL
                   OR (NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                      IS DISTINCT FROM (OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a period is closed by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'OPEN' THEN
                -- close request withdrawn or turned down: the request goes, the history stays
                IF (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'an open period keeps its history' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'CLOSED' THEN
                -- the reopen request: by the signed actor, once; nothing else of a closed period changes
                IF NOT (OLD.reopen_requested_by IS NULL AND NEW.reopen_requested_by = a AND NEW.reopen_requested_at IS NOT NULL
                        AND (NEW.closed_by, NEW.closed_at, NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                            IS NOT DISTINCT FROM (OLD.closed_by, OLD.closed_at, OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at)) THEN
                  RAISE EXCEPTION 'a closed period changes only by a reopen request' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'OPEN' THEN
                IF OLD.reopen_requested_by IS NULL OR NEW.reopened_by IS DISTINCT FROM a OR a = OLD.reopen_requested_by OR NEW.reopened_at IS NULL THEN
                  RAISE EXCEPTION 'a closed period reopens on a request decided by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a period goes OPEN → CLOSING → CLOSED' USING ERRCODE = '23514';
              ELSE
                -- same status (OPEN or CLOSING): nothing of the lifecycle changes in place
                RAISE EXCEPTION 'a period changes only by its lifecycle' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_inventory_period() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON inventory_periods FOR EACH ROW EXECUTE FUNCTION eora_guard_inventory_period();

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
              CHECK ((source_type IS NULL) = (source_id IS NULL)),
              -- an adjustment names its reason code, a reversal its reason, before it is submitted
              CHECK (doc_type <> 'STOCK_ADJUSTMENT' OR status = 'DRAFT' OR reason_code IS NOT NULL),
              CHECK (doc_type <> 'REVERSAL' OR status = 'DRAFT' OR note IS NOT NULL)
            );
            -- a document is reversed at most once (a rejected reversal request does not count)
            CREATE UNIQUE INDEX inventory_documents_reversal_uq ON inventory_documents (tenant_id, reverses_document_id)
              WHERE reverses_document_id IS NOT NULL AND status <> 'REJECTED';
            CREATE INDEX inventory_documents_list_idx ON inventory_documents (tenant_id, plant_id, effective_date DESC, id);
            CREATE INDEX inventory_documents_pending_idx ON inventory_documents (tenant_id, status) WHERE status IN ('DRAFT', 'SUBMITTED');

            -- Lifecycle guard (D-060, ADR-0002 §5): adjustments, reversals and closed-period corrections start as a request of
            -- the signed actor and are decided (posted or rejected) by another person, also against direct SQL; a posted
            -- document never changes except POSTED → REVERSED, and only once its reversal is posted; a reversal is never
            -- reversed again.
            CREATE OR REPLACE FUNCTION eora_guard_inventory_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              approved boolean := NEW.doc_type IN ('STOCK_ADJUSTMENT', 'REVERSAL') OR NEW.correction_of_document_id IS NOT NULL;
              frozen text[] := ARRAY['status', 'updated_at', 'updated_by', 'version'];
            BEGIN
              -- fail closed on its own, not only through the audit stamp
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.requested_by IS DISTINCT FROM NULL AND NEW.requested_by IS DISTINCT FROM a THEN
                  RAISE EXCEPTION 'a request is made by the signed actor' USING ERRCODE = '42501';
                END IF;
                IF approved AND (NEW.status NOT IN ('DRAFT', 'SUBMITTED') OR NEW.requested_by IS NULL OR NEW.decided_by IS NOT NULL) THEN
                  RAISE EXCEPTION 'an adjustment, reversal or closed-period correction starts as a request' USING ERRCODE = '42501';
                END IF;
                IF NEW.reverses_document_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM public.inventory_documents o
                   WHERE o.tenant_id = NEW.tenant_id AND o.id = NEW.reverses_document_id AND o.status = 'POSTED' AND o.doc_type <> 'REVERSAL'
                ) THEN
                  RAISE EXCEPTION 'only a posted document that is not a reversal can be reversed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;

              IF (NEW.tenant_id, NEW.plant_id, NEW.doc_type, NEW.idempotency_key, NEW.reverses_document_id, NEW.correction_of_document_id, NEW.requested_by)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.doc_type, OLD.idempotency_key, OLD.reverses_document_id, OLD.correction_of_document_id, OLD.requested_by) THEN
                RAISE EXCEPTION 'inventory document identity is fixed' USING ERRCODE = '23514';
              END IF;

              IF OLD.status IN ('POSTED', 'REVERSED', 'REJECTED') THEN
                IF NOT (OLD.status = 'POSTED' AND NEW.status = 'REVERSED' AND (pg_catalog.to_jsonb(NEW) - frozen) = (pg_catalog.to_jsonb(OLD) - frozen)) THEN
                  RAISE EXCEPTION 'a % inventory document never changes', pg_catalog.lower(OLD.status) USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                  SELECT 1 FROM public.inventory_documents r WHERE r.tenant_id = NEW.tenant_id AND r.reverses_document_id = NEW.id AND r.status = 'POSTED'
                ) THEN
                  RAISE EXCEPTION 'a document is reversed by a posted reversal' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;

              IF approved THEN
                IF NEW.status IN ('POSTED', 'REJECTED') THEN
                  -- the decision: a submitted request, unchanged, decided by someone who did not request it
                  IF OLD.status <> 'SUBMITTED' THEN
                    RAISE EXCEPTION 'only a submitted request is decided' USING ERRCODE = '23514';
                  END IF;
                  IF NEW.decided_by IS NULL OR NEW.decided_by <> a OR a = OLD.requested_by THEN
                    RAISE EXCEPTION 'a request is decided by a second person' USING ERRCODE = '42501';
                  END IF;
                  IF (NEW.request, NEW.reason_code, NEW.note, NEW.effective_date, NEW.original_effective_date)
                     IS DISTINCT FROM (OLD.request, OLD.reason_code, OLD.note, OLD.effective_date, OLD.original_effective_date) THEN
                    RAISE EXCEPTION 'a request is decided as it was submitted' USING ERRCODE = '23514';
                  END IF;
                ELSIF a IS DISTINCT FROM OLD.requested_by OR NEW.decided_by IS NOT NULL THEN
                  RAISE EXCEPTION 'only the requester edits, submits or withdraws a request' USING ERRCODE = '42501';
                ELSIF OLD.status = 'SUBMITTED' AND NEW.status = 'SUBMITTED'
                      AND (NEW.request, NEW.reason_code, NEW.note, NEW.effective_date) IS DISTINCT FROM (OLD.request, OLD.reason_code, OLD.note, OLD.effective_date) THEN
                  RAISE EXCEPTION 'a submitted request is withdrawn before it is changed' USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_inventory_document() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_inventory_document();

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

            -- Checked at commit, when the whole posting is written (ADR-0014): a posted document has legs; legs are written
            -- only in the transaction that posts their document (the posting service sets posted_at = now()); a reversal
            -- negates every leg of its document exactly once, same key and code (doc 04 row 13).
            CREATE OR REPLACE FUNCTION eora_check_inventory_posting() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              d record;
            BEGIN
              IF TG_TABLE_NAME = 'inventory_movements' THEN
                SELECT doc.status, doc.posted_at, doc.doc_type INTO d FROM public.inventory_documents doc WHERE doc.tenant_id = NEW.tenant_id AND doc.id = NEW.document_id;
                IF d.status NOT IN ('POSTED', 'REVERSED') OR d.posted_at IS DISTINCT FROM pg_catalog.now() THEN
                  RAISE EXCEPTION 'legs are written only with the posting of their document' USING ERRCODE = '23514';
                END IF;
                IF NEW.reverses_movement_id IS NOT NULL AND d.doc_type <> 'REVERSAL' THEN
                  RAISE EXCEPTION 'only a reversal reverses a leg' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
              END IF;
              SELECT doc.status, doc.doc_type, doc.reverses_document_id INTO d FROM public.inventory_documents doc WHERE doc.tenant_id = NEW.tenant_id AND doc.id = NEW.id;
              IF d.status NOT IN ('POSTED', 'REVERSED') THEN
                RETURN NULL;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id) THEN
                RAISE EXCEPTION 'a posted inventory document has legs' USING ERRCODE = '23514';
              END IF;
              IF d.doc_type = 'REVERSAL' AND (
                   EXISTS (
                     SELECT 1 FROM public.inventory_movements o
                      WHERE o.tenant_id = NEW.tenant_id AND o.document_id = d.reverses_document_id
                        AND NOT EXISTS (
                          SELECT 1 FROM public.inventory_movements m
                           WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.reverses_movement_id = o.id
                             AND m.qty_signed = -o.qty_signed AND m.movement_code = o.movement_code
                             AND (m.plant_id, m.location_id, m.item_id, m.lot_id, m.stock_status, m.ownership, m.uom_id)
                                 IS NOT DISTINCT FROM (o.plant_id, o.location_id, o.item_id, o.lot_id, o.stock_status, o.ownership, o.uom_id)))
                   OR EXISTS (
                     SELECT 1 FROM public.inventory_movements m
                      WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                        AND NOT EXISTS (SELECT 1 FROM public.inventory_movements o
                                         WHERE o.tenant_id = NEW.tenant_id AND o.id = m.reverses_movement_id AND o.document_id = d.reverses_document_id))) THEN
                RAISE EXCEPTION 'a reversal negates every leg of its document once' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_inventory_posting() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_posting AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION eora_check_inventory_posting();
            CREATE CONSTRAINT TRIGGER check_posting AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION eora_check_inventory_posting();

            -- M3-D4: a lot-controlled item moves and is held by lot, an item without lot control never; its flag stays as it
            -- is once the item has a lot or a leg
            CREATE OR REPLACE FUNCTION eora_guard_lot_control() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF TG_TABLE_NAME = 'items' THEN
                IF NEW.lot_controlled IS DISTINCT FROM OLD.lot_controlled
                   AND (EXISTS (SELECT 1 FROM public.lots l WHERE l.tenant_id = NEW.tenant_id AND l.item_id = NEW.id)
                        OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.item_id = NEW.id)) THEN
                  RAISE EXCEPTION 'lot control is fixed once the item has stock history' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (SELECT i.lot_controlled FROM public.items i WHERE i.tenant_id = NEW.tenant_id AND i.id = NEW.item_id) IS DISTINCT FROM (NEW.lot_id IS NOT NULL) THEN
                RAISE EXCEPTION 'a lot-controlled item moves by lot, an item without lot control without one' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_lot_control() FROM PUBLIC;
            CREATE TRIGGER guard_lot_control BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION eora_guard_lot_control();
            CREATE TRIGGER guard_lot_control BEFORE UPDATE OF lot_controlled ON items FOR EACH ROW EXECUTE FUNCTION eora_guard_lot_control();

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
            CREATE TRIGGER guard_lot_control BEFORE INSERT ON stock_balances FOR EACH ROW EXECUTE FUNCTION eora_guard_lot_control();

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
            GRANT UPDATE (status, close_requested_by, close_requested_at, closed_by, closed_at, reopen_requested_by, reopen_requested_at, reopen_reason,
                          reopened_by, reopened_at) ON inventory_periods TO eora_app;
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
            DROP TRIGGER guard_lot_control ON items;
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
            DROP FUNCTION eora_guard_lot_control();
            DROP FUNCTION eora_guard_inventory_period();
            DROP FUNCTION eora_check_inventory_posting();
            DROP FUNCTION eora_guard_inventory_document();
            SQL);
    }
};

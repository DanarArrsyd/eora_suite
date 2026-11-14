<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 7a (doc 33 §1.7, D-115, doc 04 rows 2 and 11, BR-FG-02, BR-DEM-09, doc 14 #25, #33): FG allocation and pick.
 *
 * - Allocation (`stock_allocations`, not a ledger document): delivery (`delivery.shipment.create`, DLV) reserves
 *   UNRESTRICTED FG of an FG warehouse location for one firm schedule line; on-hand stays, available drops. What is
 *   allocated, staged and delivered for a schedule line never exceeds its required quantity (tolerance 0, M4-D9).
 *   Delivery may release an allocation with the reason.
 * - Pick (`PICK` document, `delivery.shipment.post`, FGW): allocated FG moves from its FG rack to STAGING, every leg
 *   naming its schedule line; the allocation is consumed (part of it: the rest stays allocated). Unpick: the same
 *   document type moves staged goods of a schedule line back to an FG rack, unallocated.
 * - Stock at STAGING belongs to a schedule line: only picks (and later shipments, reversals) move it, and what a
 *   schedule line has there never goes below zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_movements ADD COLUMN schedule_line_id uuid;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_schedule_line_fk
              FOREIGN KEY (tenant_id, schedule_line_id) REFERENCES delivery_schedule_lines (tenant_id, id);
            CREATE INDEX inventory_movements_schedule_line_idx ON inventory_movements (tenant_id, schedule_line_id) WHERE schedule_line_id IS NOT NULL;

            ALTER TABLE stock_allocations
              ADD COLUMN release_reason text CHECK (length(release_reason) BETWEEN 3 AND 500 AND release_reason = btrim(release_reason)),
              ADD COLUMN released_by uuid,
              ADD COLUMN released_at timestamptz,
              ADD COLUMN consumed_by_document_id uuid,
              -- the remainder of a partly picked allocation stays allocated as its own row, pointing at the one consumed
              ADD COLUMN split_from uuid,
              ADD CONSTRAINT stock_allocations_consumed_fk FOREIGN KEY (tenant_id, consumed_by_document_id) REFERENCES inventory_documents (tenant_id, id),
              ADD CONSTRAINT stock_allocations_split_fk FOREIGN KEY (tenant_id, split_from) REFERENCES stock_allocations (tenant_id, id),
              ADD CONSTRAINT stock_allocations_released_check CHECK ((status = 'RELEASED') = (release_reason IS NOT NULL AND released_by IS NOT NULL AND released_at IS NOT NULL)),
              ADD CONSTRAINT stock_allocations_consumed_check CHECK (status = 'CONSUMED' OR consumed_by_document_id IS NULL);
            GRANT UPDATE (release_reason, released_by, released_at, consumed_by_document_id) ON stock_allocations TO eora_app;

            -- who allocates, releases and consumes: delivery allocates firm demand from UNRESTRICTED FG of an FG warehouse
            -- location and releases it with the reason; a pick consumes it (the rest split off, still allocated)
            CREATE OR REPLACE FUNCTION eora_guard_allocation() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              b record;
              s record;
              p record;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT sb.plant_id, sb.item_id, sb.lot_id, sb.stock_status, l.location_type INTO b FROM public.stock_balances sb
                  JOIN public.locations l ON l.tenant_id = sb.tenant_id AND l.id = sb.location_id WHERE sb.tenant_id = NEW.tenant_id AND sb.id = NEW.balance_id;
                SELECT sl.plant_id, sl.status, ol.item_id INTO s FROM public.delivery_schedule_lines sl
                  JOIN public.sales_order_lines ol ON ol.tenant_id = sl.tenant_id AND ol.id = sl.so_line_id WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.demand_id;
                IF NEW.demand_type <> 'SCHEDULE_LINE' OR NEW.status <> 'ACTIVE' OR NEW.consumed_by_document_id IS NOT NULL OR NEW.release_reason IS NOT NULL
                   OR b.location_type IS DISTINCT FROM 'FG_WAREHOUSE' OR b.stock_status IS DISTINCT FROM 'UNRESTRICTED'
                   OR s.plant_id IS DISTINCT FROM b.plant_id OR s.item_id IS DISTINCT FROM b.item_id OR s.status NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN
                  RAISE EXCEPTION 'an allocation reserves unrestricted FG of an FG warehouse location for a firm schedule line of its item and plant' USING ERRCODE = '23514';
                END IF;
                IF NEW.split_from IS NULL THEN
                  IF NOT public.eora_actor_may('delivery.shipment.create', b.plant_id) THEN
                    RAISE EXCEPTION 'FG is allocated by delivery of its plant' USING ERRCODE = '42501';
                  END IF;
                ELSE
                  -- the rest of a partly picked allocation: same balance and schedule line, by who picks
                  SELECT x.balance_id, x.demand_id, x.qty, x.status INTO p FROM public.stock_allocations x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.split_from;
                  IF p.status IS DISTINCT FROM 'CONSUMED' OR p.balance_id <> NEW.balance_id OR p.demand_id <> NEW.demand_id OR NEW.qty >= p.qty
                     OR NOT public.eora_actor_may('delivery.shipment.post', b.plant_id) THEN
                    RAISE EXCEPTION 'the rest of a picked allocation stays on its balance and schedule line, less than it was' USING ERRCODE = '23514';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.balance_id, NEW.demand_type, NEW.demand_id, NEW.qty, NEW.split_from)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.balance_id, OLD.demand_type, OLD.demand_id, OLD.qty, OLD.split_from) THEN
                RAISE EXCEPTION 'an allocation keeps its balance, schedule line and quantity' USING ERRCODE = '23514';
              END IF;
              SELECT sb.plant_id INTO b FROM public.stock_balances sb WHERE sb.tenant_id = NEW.tenant_id AND sb.id = NEW.balance_id;
              IF OLD.status = 'ACTIVE' AND NEW.status = 'RELEASED' THEN
                IF NEW.released_by IS DISTINCT FROM a OR NOT public.eora_actor_may('delivery.shipment.create', b.plant_id) THEN
                  RAISE EXCEPTION 'an allocation is released by delivery of its plant, with the reason' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'ACTIVE' AND NEW.status = 'CONSUMED' THEN
                IF NOT public.eora_actor_may('delivery.shipment.post', b.plant_id) THEN
                  RAISE EXCEPTION 'an allocation is consumed by a pick of its plant' USING ERRCODE = '42501';
                END IF;
              ELSIF NOT (OLD.status = 'CONSUMED' AND NEW.status = 'CONSUMED' AND OLD.consumed_by_document_id IS NULL AND NEW.consumed_by_document_id IS NOT NULL
                         AND (NEW.release_reason, NEW.released_by, NEW.released_at) IS NOT DISTINCT FROM (OLD.release_reason, OLD.released_by, OLD.released_at)) THEN
                RAISE EXCEPTION 'an active allocation is released or consumed once' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_allocation() FROM PUBLIC;
            CREATE TRIGGER guard_allocation BEFORE INSERT OR UPDATE ON stock_allocations FOR EACH ROW EXECUTE FUNCTION eora_guard_allocation();

            -- what a schedule line has committed: active allocations, staged goods and deliveries (BR-DEM-10)
            CREATE OR REPLACE FUNCTION eora_schedule_line_committed(t uuid, line uuid) RETURNS numeric LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT coalesce((SELECT sum(x.qty) FROM public.stock_allocations x WHERE x.tenant_id = t AND x.demand_type = 'SCHEDULE_LINE' AND x.demand_id = line AND x.status = 'ACTIVE'), 0)
                   + coalesce((SELECT sum(m.qty_signed) FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                                WHERE m.tenant_id = t AND m.schedule_line_id = line AND l.location_type IN ('STAGING', 'V_CUSTOMER')), 0)
            $fn$;
            REVOKE ALL ON FUNCTION eora_schedule_line_committed(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_schedule_line_committed(uuid, uuid) TO eora_app;

            -- as committed: no more allocated than the balance holds, no more committed than the schedule line requires
            -- (tolerance 0, M4-D9); a consumed allocation names its posted pick
            CREATE OR REPLACE FUNCTION eora_check_allocation() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              cur record;
            BEGIN
              -- the row as it is now (a deferred event carries the row of its own moment: a pick consumes, then names itself)
              SELECT x.status, x.consumed_by_document_id INTO cur FROM public.stock_allocations x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.id;
              IF cur.status = 'CONSUMED' AND NOT EXISTS (SELECT 1 FROM public.inventory_documents d WHERE d.tenant_id = NEW.tenant_id AND d.id = cur.consumed_by_document_id
                                                            AND d.doc_type = 'PICK' AND d.status IN ('POSTED', 'REVERSED')) THEN
                RAISE EXCEPTION 'a consumed allocation names the pick that consumed it' USING ERRCODE = '23514';
              END IF;
              IF (SELECT coalesce(sum(x.qty), 0) FROM public.stock_allocations x WHERE x.tenant_id = NEW.tenant_id AND x.balance_id = NEW.balance_id AND x.status = 'ACTIVE')
                 > (SELECT sb.qty FROM public.stock_balances sb WHERE sb.tenant_id = NEW.tenant_id AND sb.id = NEW.balance_id) THEN
                RAISE EXCEPTION 'no more is allocated than the stock holds' USING ERRCODE = '23514';
              END IF;
              IF public.eora_schedule_line_committed(NEW.tenant_id, NEW.demand_id)
                 > (SELECT sl.required_qty FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.demand_id) THEN
                RAISE EXCEPTION 'a schedule line gets no more allocated, staged and delivered than it requires' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_allocation() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_allocation AFTER INSERT OR UPDATE ON stock_allocations DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION eora_check_allocation();

            -- each leg naming a schedule line: picks (and later shipments) only, for the line's item and plant; staged
            -- stock belongs to a schedule line; a reversal leg keeps the schedule line of the leg it reverses
            CREATE OR REPLACE FUNCTION eora_guard_delivery_leg() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              t text;
              loc text;
              s record;
            BEGIN
              SELECT doc.doc_type INTO t FROM public.inventory_documents doc WHERE doc.tenant_id = NEW.tenant_id AND doc.id = NEW.document_id;
              SELECT l.location_type INTO loc FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id;
              IF NEW.reverses_movement_id IS NOT NULL THEN
                IF NEW.schedule_line_id IS DISTINCT FROM (SELECT m.schedule_line_id FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.reverses_movement_id) THEN
                  RAISE EXCEPTION 'a reversal leg keeps the schedule line of the leg it reverses' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF t NOT IN ('PICK', 'SHIPMENT') THEN
                IF NEW.schedule_line_id IS NOT NULL THEN
                  RAISE EXCEPTION 'only a pick or a shipment names a schedule line' USING ERRCODE = '23514';
                END IF;
                IF loc = 'STAGING' THEN
                  RAISE EXCEPTION 'stock at staging is picked for a schedule line' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              SELECT sl.plant_id, sl.status, ol.item_id INTO s FROM public.delivery_schedule_lines sl
                JOIN public.sales_order_lines ol ON ol.tenant_id = sl.tenant_id AND ol.id = sl.so_line_id WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id;
              IF NEW.schedule_line_id IS NULL OR s.plant_id IS DISTINCT FROM NEW.plant_id OR s.item_id IS DISTINCT FROM NEW.item_id THEN
                RAISE EXCEPTION 'a pick or shipment leg names a schedule line of its item and plant' USING ERRCODE = '23514';
              END IF;
              -- picked for a schedule line still to deliver (an unpick or shipment leaves staging whatever its status)
              IF loc = 'STAGING' AND NEW.qty_signed > 0 AND s.status NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN
                RAISE EXCEPTION 'goods are picked for a firm schedule line' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_delivery_leg() FROM PUBLIC;
            CREATE TRIGGER guard_delivery_leg BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION eora_guard_delivery_leg();

            -- what a schedule line has at a staging location never goes below zero (an unpick, a shipment, a reversal); any
            -- leg of the line, a reversal's too (e.g. of an unpick), keeps it within its required quantity, and goods enter
            -- staging only for a firm line
            CREATE OR REPLACE FUNCTION eora_check_staged() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF public.eora_schedule_line_committed(NEW.tenant_id, NEW.schedule_line_id)
                 > (SELECT sl.required_qty FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id) THEN
                RAISE EXCEPTION 'a schedule line gets no more allocated, staged and delivered than it requires' USING ERRCODE = '23514';
              END IF;
              IF NEW.qty_signed > 0
                 AND EXISTS (SELECT 1 FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id AND l.location_type = 'STAGING')
                 AND (SELECT sl.status FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id) NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN
                RAISE EXCEPTION 'goods are staged for a firm schedule line' USING ERRCODE = '23514';
              END IF;
              IF (SELECT sum(m.qty_signed) FROM public.inventory_movements m
                   WHERE m.tenant_id = NEW.tenant_id AND m.schedule_line_id = NEW.schedule_line_id AND m.location_id = NEW.location_id
                     AND m.item_id = NEW.item_id AND m.lot_id IS NOT DISTINCT FROM NEW.lot_id) < 0
                 AND EXISTS (SELECT 1 FROM public.locations l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.location_id AND l.location_type = 'STAGING') THEN
                RAISE EXCEPTION 'a schedule line takes from staging no more than was picked for it' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_staged() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_staged AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.schedule_line_id IS NOT NULL) EXECUTE FUNCTION eora_check_staged();

            -- who writes a pick: delivery or the FG warehouse of its plant, posting it directly
            CREATE OR REPLACE FUNCTION eora_guard_pick_document() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.doc_type <> 'PICK' THEN
                RETURN NEW;
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'POSTED' OR NEW.requested_by IS NOT NULL THEN
                  RAISE EXCEPTION 'a pick is posted directly' USING ERRCODE = '23514';
                END IF;
                IF NOT public.eora_actor_may('delivery.shipment.post', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a pick is posted by delivery or the FG warehouse of its plant' USING ERRCODE = '42501';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_pick_document() FROM PUBLIC;
            CREATE TRIGGER guard_pick BEFORE INSERT OR UPDATE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION eora_guard_pick_document();

            -- the posted pick as a whole: UNRESTRICTED goods between FG warehouse and staging, one direction, each schedule
            -- line, item and lot netting to zero; a pick (FG → staging) takes exactly what it consumed of the allocations
            CREATE OR REPLACE FUNCTION eora_check_pick() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              to_staging boolean;
            BEGIN
              IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
                RETURN NULL;
              END IF;
              IF NEW.status <> 'POSTED' THEN
                RETURN NULL;
              END IF;
              to_staging := EXISTS (SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                                     WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.qty_signed > 0 AND l.location_type = 'STAGING');
              IF EXISTS (SELECT 1 FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                          WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND NOT coalesce(
                            m.movement_code = 'PICK' AND m.stock_status = 'UNRESTRICTED' AND m.wo_id IS NULL AND m.run_id IS NULL AND l.plant_id = NEW.plant_id
                            AND l.location_type = CASE WHEN (m.qty_signed > 0) = to_staging THEN 'STAGING' ELSE 'FG_WAREHOUSE' END, false))
                 OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                             GROUP BY m.schedule_line_id, m.item_id, m.lot_id HAVING sum(m.qty_signed) <> 0)
              THEN
                RAISE EXCEPTION 'a pick moves unrestricted goods of a schedule line between an FG warehouse location and staging, one way' USING ERRCODE = '23514';
              END IF;
              IF to_staging AND EXISTS (
                   SELECT 1 FROM (
                     SELECT m.schedule_line_id AS line, m.location_id AS loc, m.lot_id AS lot, -sum(m.qty_signed) AS q
                       FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id AND m.qty_signed < 0
                      GROUP BY 1, 2, 3) picked
                   FULL JOIN (
                     SELECT x.demand_id AS line, sb.location_id AS loc, sb.lot_id AS lot,
                            sum(x.qty - coalesce((SELECT sum(r.qty) FROM public.stock_allocations r WHERE r.tenant_id = x.tenant_id AND r.split_from = x.id), 0)) AS q
                       FROM public.stock_allocations x JOIN public.stock_balances sb ON sb.tenant_id = x.tenant_id AND sb.id = x.balance_id
                      WHERE x.tenant_id = NEW.tenant_id AND x.consumed_by_document_id = NEW.id
                      GROUP BY 1, 2, 3) consumed
                     ON consumed.line = picked.line AND consumed.loc = picked.loc AND consumed.lot IS NOT DISTINCT FROM picked.lot
                  WHERE picked.q IS DISTINCT FROM consumed.q)
              THEN
                RAISE EXCEPTION 'a pick takes exactly what it consumes of the allocations' USING ERRCODE = '23514';
              END IF;
              IF NOT to_staging AND EXISTS (SELECT 1 FROM public.stock_allocations x WHERE x.tenant_id = NEW.tenant_id AND x.consumed_by_document_id = NEW.id) THEN
                RAISE EXCEPTION 'an unpick consumes no allocation' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_pick() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_pick AFTER INSERT OR UPDATE ON inventory_documents DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (NEW.doc_type = 'PICK') EXECUTE FUNCTION eora_check_pick();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS check_pick ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_check_pick();
            DROP TRIGGER IF EXISTS guard_pick ON inventory_documents;
            DROP FUNCTION IF EXISTS eora_guard_pick_document();
            DROP TRIGGER IF EXISTS check_staged ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_check_staged();
            DROP TRIGGER IF EXISTS guard_delivery_leg ON inventory_movements;
            DROP FUNCTION IF EXISTS eora_guard_delivery_leg();
            DROP TRIGGER IF EXISTS check_allocation ON stock_allocations;
            DROP FUNCTION IF EXISTS eora_check_allocation();
            DROP FUNCTION IF EXISTS eora_schedule_line_committed(uuid, uuid);
            DROP TRIGGER IF EXISTS guard_allocation ON stock_allocations;
            DROP FUNCTION IF EXISTS eora_guard_allocation();
            REVOKE UPDATE (release_reason, released_by, released_at, consumed_by_document_id) ON stock_allocations FROM eora_app;
            ALTER TABLE stock_allocations DROP CONSTRAINT stock_allocations_consumed_check, DROP CONSTRAINT stock_allocations_released_check,
              DROP CONSTRAINT stock_allocations_split_fk, DROP CONSTRAINT stock_allocations_consumed_fk,
              DROP COLUMN split_from, DROP COLUMN consumed_by_document_id, DROP COLUMN released_at, DROP COLUMN released_by, DROP COLUMN release_reason;
            DROP INDEX IF EXISTS inventory_movements_schedule_line_idx;
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_schedule_line_fk, DROP COLUMN schedule_line_id;
            SQL);
    }
};

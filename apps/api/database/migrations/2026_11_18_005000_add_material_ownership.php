<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** One immutable material owner per lot and production posting; doc 37, D-135. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE lots ADD COLUMN owner_customer_id uuid,
              ADD COLUMN owner_company_id uuid,
              ADD CONSTRAINT lots_owner_customer_fk FOREIGN KEY (tenant_id, owner_customer_id) REFERENCES customers(tenant_id,id),
              ADD CONSTRAINT lots_owner_company_fk FOREIGN KEY (tenant_id, owner_company_id) REFERENCES companies(tenant_id,id),
              ADD CONSTRAINT lots_one_owner CHECK (owner_customer_id IS NULL OR owner_company_id IS NULL);
            CREATE INDEX lots_owner_customer_idx ON lots(tenant_id,owner_customer_id) WHERE owner_customer_id IS NOT NULL;
            ALTER TABLE work_orders ADD COLUMN production_customer_id uuid,
              ADD CONSTRAINT work_orders_production_customer_fk FOREIGN KEY(tenant_id,production_customer_id) REFERENCES customers(tenant_id,id);
            GRANT UPDATE(production_customer_id) ON work_orders TO eora_app;
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_ownership_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_ownership_check CHECK(ownership IN ('OWN','CUSTOMER'));
            ALTER TABLE stock_balances DROP CONSTRAINT stock_balances_ownership_check;
            ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_ownership_check CHECK(ownership IN ('OWN','CUSTOMER'));

            CREATE TABLE daily_lot_counters (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              namespace text NOT NULL CHECK(length(namespace) BETWEEN 1 AND 26),
              day date NOT NULL,
              last_no integer NOT NULL CHECK(last_no > 0),
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              PRIMARY KEY(tenant_id,namespace,day)
            );
            ALTER TABLE daily_lot_counters ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON daily_lot_counters TO eora_app
              USING(tenant_id = (SELECT c.tenant FROM ctx_api.context() c))
              WITH CHECK(tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT,INSERT ON daily_lot_counters TO eora_app;
            GRANT UPDATE(last_no) ON daily_lot_counters TO eora_app;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON daily_lot_counters FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            CREATE OR REPLACE FUNCTION eora_guard_lot_owner() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE parent record;
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                IF (NEW.owner_customer_id,NEW.owner_company_id) IS DISTINCT FROM (OLD.owner_customer_id,OLD.owner_company_id) THEN
                  RAISE EXCEPTION 'a lot keeps its material owner' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.parent_lot_id IS NOT NULL THEN
                SELECT l.owner_customer_id,l.owner_company_id INTO parent FROM public.lots l WHERE l.tenant_id=NEW.tenant_id AND l.id=NEW.parent_lot_id;
                NEW.owner_customer_id := parent.owner_customer_id;
                NEW.owner_company_id := parent.owner_company_id;
              END IF;
              IF NEW.origin='RECEIPT' AND NEW.owner_customer_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.customers c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.owner_customer_id AND c.status='ACTIVE') THEN
                RAISE EXCEPTION 'choose an active material owner customer' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_lot_owner() FROM PUBLIC;
            CREATE TRIGGER guard_owner BEFORE INSERT OR UPDATE ON lots FOR EACH ROW EXECUTE FUNCTION eora_guard_lot_owner();

            CREATE OR REPLACE FUNCTION eora_guard_work_order_customer() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE customer uuid;
            BEGIN
              IF TG_OP='UPDATE' AND NOT(OLD.status='DRAFT' AND NEW.status='DRAFT') AND NEW.production_customer_id IS DISTINCT FROM OLD.production_customer_id THEN
                RAISE EXCEPTION 'a submitted work order keeps its production customer' USING ERRCODE='23514';
              END IF;
              IF NEW.schedule_line_id IS NOT NULL THEN
                SELECT h.customer_id INTO customer FROM public.delivery_schedule_lines s
                  JOIN public.sales_order_lines l ON l.tenant_id=s.tenant_id AND l.id=s.so_line_id
                  JOIN public.sales_order_headers h ON h.tenant_id=l.tenant_id AND h.id=l.sales_order_id
                  WHERE s.tenant_id=NEW.tenant_id AND s.id=NEW.schedule_line_id;
                IF NEW.production_customer_id IS NOT NULL AND NEW.production_customer_id IS DISTINCT FROM customer THEN
                  RAISE EXCEPTION 'the production customer must match the delivery customer' USING ERRCODE='23514';
                END IF;
              END IF;
              IF NEW.production_customer_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.customers c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.production_customer_id AND c.status='ACTIVE')
                 AND (TG_OP='INSERT' OR NEW.production_customer_id IS DISTINCT FROM OLD.production_customer_id) THEN
                RAISE EXCEPTION 'choose an active production customer' USING ERRCODE='23514';
              END IF;
              -- A Daily Recovery order made before this migration from several deliveries has no customer at all: it keeps
              -- working as a company-material order (it can never take customer material) instead of being frozen. Once a
              -- customer is attributed, it must be the planning customer.
              IF coalesce(NEW.production_customer_id,customer) IS NOT NULL
                 AND EXISTS(SELECT 1 FROM public.daily_recovery_plans p WHERE p.tenant_id=NEW.tenant_id AND p.work_order_id=NEW.id
                        AND p.customer_id IS DISTINCT FROM coalesce(NEW.production_customer_id,customer)) THEN
                RAISE EXCEPTION 'a Daily Recovery work order keeps its planning customer' USING ERRCODE='23514';
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_work_order_customer() FROM PUBLIC;
            CREATE TRIGGER guard_customer BEFORE INSERT OR UPDATE ON work_orders FOR EACH ROW EXECUTE FUNCTION eora_guard_work_order_customer();

            -- Owners are not client-provided ledger labels. Both physical and virtual legs derive them from the lot.
            CREATE OR REPLACE FUNCTION eora_guard_stock_owner() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE owner uuid; company uuid; plant_company uuid; customer uuid;
            BEGIN
              SELECT l.owner_customer_id,l.owner_company_id INTO owner,company FROM public.lots l WHERE l.tenant_id=NEW.tenant_id AND l.id=NEW.lot_id;
              SELECT p.company_id INTO plant_company FROM public.plants p WHERE p.tenant_id=NEW.tenant_id AND p.id=NEW.plant_id;
              IF company IS NOT NULL AND company IS DISTINCT FROM plant_company THEN
                RAISE EXCEPTION 'company-owned material stays in its owning company' USING ERRCODE='23514';
              END IF;
              IF NEW.ownership IS DISTINCT FROM (CASE WHEN owner IS NULL THEN 'OWN' ELSE 'CUSTOMER' END) THEN
                RAISE EXCEPTION 'ledger ownership must match the lot owner' USING ERRCODE='23514';
              END IF;
              IF TG_TABLE_NAME='inventory_movements' THEN
                IF NEW.reverses_movement_id IS NOT NULL THEN RETURN NEW; END IF;
                IF owner IS NOT NULL AND NEW.wo_id IS NOT NULL THEN
                  SELECT coalesce(w.production_customer_id,h.customer_id) INTO customer FROM public.work_orders w
                    LEFT JOIN public.delivery_schedule_lines s ON s.tenant_id=w.tenant_id AND s.id=w.schedule_line_id
                    LEFT JOIN public.sales_order_lines l ON l.tenant_id=s.tenant_id AND l.id=s.so_line_id
                    LEFT JOIN public.sales_order_headers h ON h.tenant_id=l.tenant_id AND h.id=l.sales_order_id
                    WHERE w.tenant_id=NEW.tenant_id AND w.id=NEW.wo_id;
                  IF customer IS DISTINCT FROM owner THEN
                    RAISE EXCEPTION 'customer-owned material only serves its owner customer work order' USING ERRCODE='23514';
                  END IF;
                END IF;
                IF owner IS NOT NULL AND NEW.schedule_line_id IS NOT NULL THEN
                  SELECT h.customer_id INTO customer FROM public.delivery_schedule_lines s
                    JOIN public.sales_order_lines l ON l.tenant_id=s.tenant_id AND l.id=s.so_line_id
                    JOIN public.sales_order_headers h ON h.tenant_id=l.tenant_id AND h.id=l.sales_order_id
                    WHERE s.tenant_id=NEW.tenant_id AND s.id=NEW.schedule_line_id;
                  IF customer IS DISTINCT FROM owner THEN
                    RAISE EXCEPTION 'customer-owned goods only serve their owner customer delivery' USING ERRCODE='23514';
                  END IF;
                END IF;
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_stock_owner() FROM PUBLIC;
            CREATE TRIGGER guard_owner BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION eora_guard_stock_owner();
            CREATE TRIGGER guard_owner BEFORE INSERT OR UPDATE ON stock_balances FOR EACH ROW EXECUTE FUNCTION eora_guard_stock_owner();

            CREATE OR REPLACE FUNCTION eora_guard_allocation_owner() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE owner uuid; customer uuid;
            BEGIN
              SELECT l.owner_customer_id INTO owner FROM public.stock_balances b JOIN public.lots l ON l.tenant_id=b.tenant_id AND l.id=b.lot_id WHERE b.tenant_id=NEW.tenant_id AND b.id=NEW.balance_id;
              IF owner IS NOT NULL THEN
                SELECT h.customer_id INTO customer FROM public.delivery_schedule_lines s JOIN public.sales_order_lines l ON l.tenant_id=s.tenant_id AND l.id=s.so_line_id
                  JOIN public.sales_order_headers h ON h.tenant_id=l.tenant_id AND h.id=l.sales_order_id WHERE s.tenant_id=NEW.tenant_id AND s.id=NEW.demand_id;
                IF customer IS DISTINCT FROM owner THEN RAISE EXCEPTION 'customer-owned goods only allocate to their owner customer' USING ERRCODE='23514'; END IF;
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_allocation_owner() FROM PUBLIC;
            CREATE TRIGGER guard_owner BEFORE INSERT ON stock_allocations FOR EACH ROW EXECUTE FUNCTION eora_guard_allocation_owner();

            CREATE OR REPLACE FUNCTION eora_check_production_owner() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE doc record; owners integer;
            BEGIN
              SELECT d.doc_type,d.source_id INTO doc FROM public.inventory_documents d WHERE d.tenant_id=NEW.tenant_id AND d.id=NEW.document_id;
              IF doc.doc_type <> 'PRODUCTION_POSTING' THEN RETURN NULL; END IF;
              SELECT count(DISTINCT CASE WHEN l.owner_customer_id IS NOT NULL THEN 'CUSTOMER:'||l.owner_customer_id::text
                       ELSE 'OWN:'||coalesce(l.owner_company_id,p.company_id)::text END) INTO owners
                FROM public.inventory_movements m LEFT JOIN public.lots l ON l.tenant_id=m.tenant_id AND l.id=m.lot_id
                JOIN public.plants p ON p.tenant_id=m.tenant_id AND p.id=m.plant_id WHERE m.tenant_id=NEW.tenant_id AND m.document_id=NEW.document_id;
              IF owners > 1 THEN RAISE EXCEPTION 'one production posting and all its outputs have one material owner' USING ERRCODE='23514'; END IF;
              IF EXISTS(SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id=NEW.tenant_id AND m.document_id=NEW.document_id AND m.ownership='CUSTOMER')
                 AND NOT EXISTS(SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id=NEW.tenant_id AND m.document_id=NEW.document_id AND m.movement_code='CONSUME') THEN
                RAISE EXCEPTION 'customer-owned output requires traceable customer material consumption' USING ERRCODE='23514';
              END IF;
              RETURN NULL;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_check_production_owner() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_owner AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION eora_check_production_owner();
            SQL);
    }

    public function down(): void
    {
        // Customer assets cannot be discarded or relabelled OWN to force a downgrade.
        DB::unprepared(<<<'SQL'
            ALTER TABLE lots ADD CONSTRAINT rollback_requires_no_customer_assets CHECK(owner_customer_id IS NULL);
            DROP TRIGGER check_owner ON inventory_movements;
            DROP FUNCTION eora_check_production_owner();
            DROP TRIGGER guard_owner ON stock_allocations;
            DROP FUNCTION eora_guard_allocation_owner();
            DROP TRIGGER guard_owner ON stock_balances;
            DROP TRIGGER guard_owner ON inventory_movements;
            DROP FUNCTION eora_guard_stock_owner();
            DROP TRIGGER guard_customer ON work_orders;
            DROP FUNCTION eora_guard_work_order_customer();
            DROP TRIGGER guard_owner ON lots;
            DROP FUNCTION eora_guard_lot_owner();
            DROP TABLE daily_lot_counters;
            ALTER TABLE work_orders DROP COLUMN production_customer_id;
            ALTER TABLE lots DROP COLUMN owner_customer_id, DROP COLUMN owner_company_id;
            ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_ownership_check;
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_ownership_check CHECK(ownership='OWN');
            ALTER TABLE stock_balances DROP CONSTRAINT stock_balances_ownership_check;
            ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_ownership_check CHECK(ownership='OWN');
            SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 2b (doc 29 §1.2, doc 26 §2.2, D-030, D-034, D-073): customers, suppliers, customer part mapping.
 *
 * - `customers` and `suppliers`: tenant codes (immutable), name, status. A Customer PO and a supplier PO are different
 *   documents (doc 00), so the two masters stay separate.
 * - `customer_items` (Customer Part Mapping): the customer's part number for one of our items, with the customer's
 *   drawing revision and an inclusive effective range. One customer part number maps to exactly one item on every day
 *   (exclusion constraint), so demand identity (D-034) can never resolve to two items; an item may be supplied to many
 *   customers. A new mapping closes the open one of the same customer part the day before; a closed mapping never
 *   changes its item, number or dates again (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE customers (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code)
            );
            CREATE INDEX customers_search_trgm_idx ON customers USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);

            CREATE TABLE suppliers (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code)
            );
            CREATE INDEX suppliers_search_trgm_idx ON suppliers USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);

            CREATE TABLE customer_items (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              customer_id uuid NOT NULL,
              item_id uuid NOT NULL,
              customer_part_no text NOT NULL CHECK (length(customer_part_no) BETWEEN 1 AND 60 AND customer_part_no = btrim(customer_part_no)),
              customer_drawing_rev text CHECK (length(customer_drawing_rev) BETWEEN 1 AND 20),
              effective_from date NOT NULL,
              effective_to date,
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, customer_id, customer_part_no, effective_from),
              FOREIGN KEY (tenant_id, customer_id) REFERENCES customers (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              CHECK (effective_to IS NULL OR effective_to >= effective_from),
              -- one item per customer part number on every day (D-034 demand identity)
              EXCLUDE USING gist (
                tenant_id WITH =, customer_id WITH =, customer_part_no WITH =, daterange(effective_from, effective_to, '[]') WITH &&
              )
            );
            CREATE INDEX customer_items_item_idx ON customer_items (tenant_id, item_id);
            CREATE INDEX customer_items_part_trgm_idx ON customer_items USING gin (customer_part_no ext.gin_trgm_ops);

            -- identity and dates of a mapping change only by closing it once (effective_to from NULL); drawing revision and
            -- status stay editable
            CREATE OR REPLACE FUNCTION eora_guard_customer_item() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.customer_id <> OLD.customer_id OR NEW.item_id <> OLD.item_id OR NEW.customer_part_no <> OLD.customer_part_no
                 OR NEW.effective_from <> OLD.effective_from
                 OR (NEW.effective_to IS DISTINCT FROM OLD.effective_to AND (OLD.effective_to IS NOT NULL OR NEW.effective_to IS NULL)) THEN
                RAISE EXCEPTION 'a customer part mapping changes its identity or dates only by being closed once' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_customer_item() FROM PUBLIC;
            CREATE TRIGGER guard_mapping BEFORE UPDATE ON customer_items FOR EACH ROW EXECUTE FUNCTION eora_guard_customer_item();

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON suppliers FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON customer_items FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            ALTER TABLE customers ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON customers TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON customers TO eora_app;
            GRANT UPDATE (name, status) ON customers TO eora_app;
            ALTER TABLE suppliers ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON suppliers TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON suppliers TO eora_app;
            GRANT UPDATE (name, status) ON suppliers TO eora_app;
            ALTER TABLE customer_items ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON customer_items TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON customer_items TO eora_app;
            GRANT UPDATE (customer_drawing_rev, effective_to, status) ON customer_items TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE customer_items;
            DROP FUNCTION eora_guard_customer_item();
            DROP TABLE suppliers;
            DROP TABLE customers;
            SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 1 (doc 29 §1.1, doc 26 §2.1, doc 17, ADR-0002 §3): warehouses and locations.
 *
 * - Codes are immutable (no UPDATE grant): later postings reference them; name, type and status are maintained.
 * - Location types per ADR-0002 §3. Physical locations except `MAT_AT_PROD` belong to a warehouse of the same plant
 *   (composite FK); `MAT_AT_PROD` belongs to a work center (column and FK arrive with work centers, step 1b).
 * - Virtual locations (`V_*`, ledger counterparties that never hold balances) exist once per plant with code = type,
 *   are created by a trigger whenever a plant is inserted (provisioning and the API alike) and can never be renamed
 *   to another code or deactivated. Plants created before this migration get theirs from `eora:tenants:sync`.
 * - Forced RLS bound to the signed context, composite tenant FKs, no DELETE for runtime roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE warehouses (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              warehouse_type text NOT NULL CHECK (warehouse_type IN ('RAW_MATERIAL', 'FINISHED_GOODS', 'GENERAL')),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, plant_id, code),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );

            CREATE TABLE locations (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              warehouse_id uuid,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              location_type text NOT NULL CHECK (location_type IN (
                'RM_WAREHOUSE', 'MAT_AT_PROD', 'CUSTODY_TRANSIT', 'QC_AREA', 'NG_AREA', 'SCRAP_YARD', 'FG_WAREHOUSE', 'STAGING',
                'V_SUPPLIER', 'V_CUSTOMER', 'V_CONSUMED', 'V_PRODUCED', 'V_ADJUSTMENT', 'V_OPENING')),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, plant_id, code),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, plant_id, warehouse_id) REFERENCES warehouses (tenant_id, plant_id, id),
              -- virtual: no warehouse, code = type, always active
              CHECK (location_type NOT LIKE 'V\_%' OR (warehouse_id IS NULL AND code = location_type AND status = 'ACTIVE')),
              -- the V_ prefix is reserved for virtual locations
              CHECK (location_type LIKE 'V\_%' OR code NOT LIKE 'V\_%'),
              -- physical (MAT_AT_PROD excepted until work centers exist): inside a warehouse of the same plant
              CHECK (location_type LIKE 'V\_%' OR location_type = 'MAT_AT_PROD' OR warehouse_id IS NOT NULL),
              CHECK (location_type <> 'MAT_AT_PROD' OR warehouse_id IS NULL)
            );
            CREATE UNIQUE INDEX locations_virtual_uq ON locations (tenant_id, plant_id, location_type) WHERE location_type LIKE 'V\_%';
            CREATE INDEX locations_warehouse_idx ON locations (tenant_id, warehouse_id) WHERE warehouse_id IS NOT NULL;

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON warehouses FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON locations FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- one set of virtual locations per plant, written with the plant in the same transaction (invoker: RLS applies)
            CREATE OR REPLACE FUNCTION eora_plant_virtual_locations() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              INSERT INTO public.locations (tenant_id, plant_id, code, name, location_type)
              SELECT NEW.tenant_id, NEW.id, v.t, v.n, v.t
                FROM (VALUES ('V_SUPPLIER', 'Supplier (virtual)'), ('V_CUSTOMER', 'Customer (virtual)'),
                             ('V_CONSUMED', 'Consumed (virtual)'), ('V_PRODUCED', 'Produced (virtual)'),
                             ('V_ADJUSTMENT', 'Adjustment (virtual)'), ('V_OPENING', 'Opening balance (virtual)')) AS v (t, n)
              ON CONFLICT (tenant_id, plant_id, code) DO NOTHING;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_plant_virtual_locations() FROM PUBLIC;
            CREATE TRIGGER virtual_locations AFTER INSERT ON plants FOR EACH ROW EXECUTE FUNCTION eora_plant_virtual_locations();

            ALTER TABLE warehouses ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON warehouses TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON warehouses TO eora_app;
            GRANT UPDATE (name, warehouse_type, status) ON warehouses TO eora_app;
            ALTER TABLE locations ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON locations TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON locations TO eora_app;
            GRANT UPDATE (name, status) ON locations TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER virtual_locations ON plants;
            DROP FUNCTION eora_plant_virtual_locations();
            DROP TABLE locations;
            DROP TABLE warehouses;
            SQL);
    }
};

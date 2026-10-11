<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 3 (doc 25 §1, §9 row 10; ADR-0012; D-044, D-B1-14): business and shift calendars.
 * Hierarchy: tenant default (plant_id NULL) → plant override; versioned by effective_from so historical production
 * dates and weekly buckets never move. Forced RLS on the signed context; audit columns stamped by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE business_calendars (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid,
              timezone text NOT NULL CHECK (timezone ~ '^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$'),
              week_start text NOT NULL DEFAULT 'ISO_MONDAY' CHECK (week_start = 'ISO_MONDAY'),
              production_day_starts_at time NOT NULL,
              effective_from date NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE NULLS NOT DISTINCT (tenant_id, plant_id, effective_from),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );

            CREATE TABLE calendar_exceptions (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              calendar_id uuid NOT NULL,
              exception_date date NOT NULL,
              kind text NOT NULL CHECK (kind IN ('HOLIDAY', 'NON_WORKING', 'WORKING')),
              description text CHECK (length(description) <= 200),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, calendar_id, exception_date),
              FOREIGN KEY (tenant_id, calendar_id) REFERENCES business_calendars (tenant_id, id)
            );

            CREATE TABLE shift_calendars (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
              effective_from date NOT NULL,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE NULLS NOT DISTINCT (tenant_id, plant_id, effective_from),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );

            CREATE TABLE shifts (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              shift_calendar_id uuid NOT NULL,
              code text NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,9}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
              starts_at time NOT NULL,
              ends_at time NOT NULL CHECK (ends_at <> starts_at),
              -- a shift ending at or before its start runs past midnight and belongs wholly to the production date of
              -- its start (ADR-0012 §4)
              crosses_midnight boolean GENERATED ALWAYS AS (ends_at < starts_at) STORED,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, shift_calendar_id, code),
              FOREIGN KEY (tenant_id, shift_calendar_id) REFERENCES shift_calendars (tenant_id, id)
            );

            CREATE INDEX business_calendars_lookup_idx ON business_calendars (tenant_id, plant_id, effective_from DESC);
            CREATE INDEX shift_calendars_lookup_idx ON shift_calendars (tenant_id, plant_id, effective_from DESC);
            CREATE INDEX calendar_exceptions_calendar_idx ON calendar_exceptions (tenant_id, calendar_id, exception_date);
            CREATE INDEX shifts_calendar_idx ON shifts (tenant_id, shift_calendar_id);

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON business_calendars FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON calendar_exceptions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON shift_calendars FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON shifts FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            ALTER TABLE business_calendars ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON business_calendars TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            ALTER TABLE calendar_exceptions ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON calendar_exceptions TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            ALTER TABLE shift_calendars ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON shift_calendars TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            ALTER TABLE shifts ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON shifts TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));

            -- Calendar and shift versions are immutable once written: a change is a new version with its own
            -- effective_from, so production dates and weekly buckets already derived never move (ADR-0012). Exceptions
            -- (holidays / non-working days) may be corrected; nothing is deleted by the runtime role.
            GRANT SELECT, INSERT ON business_calendars, shift_calendars, shifts TO eora_app;
            GRANT SELECT, INSERT, UPDATE ON calendar_exceptions TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE shifts; DROP TABLE shift_calendars; DROP TABLE calendar_exceptions; DROP TABLE business_calendars;');
    }
};

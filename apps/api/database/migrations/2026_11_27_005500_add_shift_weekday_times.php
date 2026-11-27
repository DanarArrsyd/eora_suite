<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Calendars screen (doc 40, D-142).
 *
 * - `shift_weekday_times`: a shift's start, end and break per ISO weekday (C-1, C-2). A shift with weekday rows runs only
 *   on those weekdays; a shift without any keeps its own `starts_at` / `ends_at` every day (the M1 behaviour).
 * - Versions stay immutable once started (ADR-0012). A version that has not started yet may be removed whole (its shifts
 *   and weekday times with it) so a planner can correct it before it takes effect; exception days on or after the
 *   plant's today may be corrected or removed, earlier ones stay.
 * - `master.calendar.view` / `master.calendar.create` (C-4): CSA and PPC manage; the API checks them, the database keeps the
 *   version rules.
 *
 * Existing tenants receive the template permissions through `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, module, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['master.calendar.view', 'master', 'view', false, ['CSA', 'PPC', 'PRL', 'MAT', 'INV', 'AUD']],
        ['master.calendar.create', 'master', 'create', false, ['CSA', 'PPC']],
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
            CREATE TABLE shift_weekday_times (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              shift_id uuid NOT NULL,
              iso_weekday smallint NOT NULL CHECK (iso_weekday BETWEEN 1 AND 7),
              starts_at time NOT NULL,
              ends_at time NOT NULL CHECK (ends_at <> starts_at),
              break_minutes integer NOT NULL DEFAULT 0 CHECK (break_minutes >= 0),
              -- as `shifts`: ending at or before its start runs past midnight and belongs to the production date of its start
              crosses_midnight boolean GENERATED ALWAYS AS (ends_at < starts_at) STORED,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, shift_id, iso_weekday),
              FOREIGN KEY (tenant_id, shift_id) REFERENCES shifts (tenant_id, id) ON DELETE CASCADE,
              -- a break shorter than the shift: work time stays above zero
              CHECK (break_minutes < mod((EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60)::integer + 1440, 1440))
            );
            CREATE INDEX shift_weekday_times_shift_idx ON shift_weekday_times (tenant_id, shift_id);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON shift_weekday_times FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE shift_weekday_times ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON shift_weekday_times TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, DELETE ON shift_weekday_times TO eora_app;

            -- the local date "today" of a calendar scope (a plant, or the tenant default when plant is NULL)
            CREATE OR REPLACE FUNCTION eora_calendar_scope_today(tenant uuid, plant uuid) RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT (pg_catalog.statement_timestamp() AT TIME ZONE COALESCE(
                       (SELECT p.timezone FROM public.plants p WHERE p.tenant_id = tenant AND p.id = plant),
                       (SELECT t.timezone FROM public.tenants t WHERE t.id = tenant), 'UTC'))::date
            $fn$;
            REVOKE ALL ON FUNCTION eora_calendar_scope_today(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_calendar_scope_today(uuid, uuid) TO eora_app;

            -- weekday times are written with their shift, while its version has not started (or in the same transaction)
            CREATE OR REPLACE FUNCTION eora_guard_shift_weekday_times() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              cal record;
            BEGIN
              SELECT c.effective_from, c.plant_id, (c.xmin = pg_catalog.pg_current_xact_id()::xid) AS same_tx INTO cal
                FROM public.shifts s JOIN public.shift_calendars c ON c.tenant_id = s.tenant_id AND c.id = s.shift_calendar_id
               WHERE s.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND s.id = COALESCE(NEW.shift_id, OLD.shift_id);
              IF cal.effective_from IS NOT NULL AND NOT cal.same_tx
                 AND cal.effective_from <= public.eora_calendar_scope_today(COALESCE(NEW.tenant_id, OLD.tenant_id), cal.plant_id) THEN
                RAISE EXCEPTION 'shift calendar version is already in effect; create a new version instead' USING ERRCODE = 'check_violation';
              END IF;
              RETURN COALESCE(NEW, OLD);
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_shift_weekday_times() FROM PUBLIC;
            CREATE TRIGGER version_guard BEFORE INSERT OR DELETE ON shift_weekday_times FOR EACH ROW EXECUTE FUNCTION eora_guard_shift_weekday_times();

            -- a calendar version (business or shift) is removed only before it takes effect; its shifts go with it
            CREATE OR REPLACE FUNCTION eora_guard_calendar_version_delete() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF OLD.effective_from <= public.eora_calendar_scope_today(OLD.tenant_id, OLD.plant_id) THEN
                RAISE EXCEPTION 'a calendar version in effect is never removed; create a new version instead' USING ERRCODE = 'check_violation';
              END IF;
              RETURN OLD;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_calendar_version_delete() FROM PUBLIC;
            CREATE TRIGGER delete_guard BEFORE DELETE ON business_calendars FOR EACH ROW EXECUTE FUNCTION eora_guard_calendar_version_delete();
            CREATE TRIGGER delete_guard BEFORE DELETE ON shift_calendars FOR EACH ROW EXECUTE FUNCTION eora_guard_calendar_version_delete();

            CREATE OR REPLACE FUNCTION eora_guard_shift_delete() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              cal record;
            BEGIN
              SELECT c.effective_from, c.plant_id INTO cal FROM public.shift_calendars c WHERE c.tenant_id = OLD.tenant_id AND c.id = OLD.shift_calendar_id;
              IF cal.effective_from IS NOT NULL AND cal.effective_from <= public.eora_calendar_scope_today(OLD.tenant_id, cal.plant_id) THEN
                RAISE EXCEPTION 'shift calendar version is already in effect; create a new version instead' USING ERRCODE = 'check_violation';
              END IF;
              RETURN OLD;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_shift_delete() FROM PUBLIC;
            CREATE TRIGGER delete_guard BEFORE DELETE ON shifts FOR EACH ROW EXECUTE FUNCTION eora_guard_shift_delete();

            -- exception days from the scope's today on may change or go; earlier ones are history
            CREATE OR REPLACE FUNCTION eora_guard_calendar_exceptions() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              scope_plant uuid;
              today date;
            BEGIN
              SELECT b.plant_id INTO scope_plant FROM public.business_calendars b
               WHERE b.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND b.id = COALESCE(NEW.calendar_id, OLD.calendar_id);
              today := public.eora_calendar_scope_today(COALESCE(NEW.tenant_id, OLD.tenant_id), scope_plant);
              IF TG_OP IN ('UPDATE', 'DELETE') AND OLD.exception_date < today THEN
                RAISE EXCEPTION 'an exception day in the past is history and stays' USING ERRCODE = 'check_violation';
              END IF;
              IF TG_OP = 'UPDATE' AND (NEW.calendar_id, NEW.exception_date) IS DISTINCT FROM (OLD.calendar_id, OLD.exception_date) THEN
                RAISE EXCEPTION 'an exception day keeps its calendar and date' USING ERRCODE = '23514';
              END IF;
              RETURN COALESCE(NEW, OLD);
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_calendar_exceptions() FROM PUBLIC;
            CREATE TRIGGER change_guard BEFORE UPDATE OR DELETE ON calendar_exceptions FOR EACH ROW EXECUTE FUNCTION eora_guard_calendar_exceptions();

            ALTER TABLE shifts DROP CONSTRAINT shifts_tenant_id_shift_calendar_id_fkey,
              ADD CONSTRAINT shifts_tenant_id_shift_calendar_id_fkey FOREIGN KEY (tenant_id, shift_calendar_id) REFERENCES shift_calendars (tenant_id, id) ON DELETE CASCADE;
            ALTER TABLE calendar_exceptions DROP CONSTRAINT calendar_exceptions_tenant_id_calendar_id_fkey,
              ADD CONSTRAINT calendar_exceptions_tenant_id_calendar_id_fkey FOREIGN KEY (tenant_id, calendar_id) REFERENCES business_calendars (tenant_id, id) ON DELETE CASCADE;
            GRANT DELETE ON business_calendars, shift_calendars, shifts, calendar_exceptions TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE DELETE ON business_calendars, shift_calendars, shifts, calendar_exceptions FROM eora_app;
            ALTER TABLE calendar_exceptions DROP CONSTRAINT calendar_exceptions_tenant_id_calendar_id_fkey,
              ADD CONSTRAINT calendar_exceptions_tenant_id_calendar_id_fkey FOREIGN KEY (tenant_id, calendar_id) REFERENCES business_calendars (tenant_id, id);
            ALTER TABLE shifts DROP CONSTRAINT shifts_tenant_id_shift_calendar_id_fkey,
              ADD CONSTRAINT shifts_tenant_id_shift_calendar_id_fkey FOREIGN KEY (tenant_id, shift_calendar_id) REFERENCES shift_calendars (tenant_id, id);
            DROP TRIGGER IF EXISTS change_guard ON calendar_exceptions;
            DROP FUNCTION IF EXISTS eora_guard_calendar_exceptions();
            DROP TRIGGER IF EXISTS delete_guard ON shifts;
            DROP FUNCTION IF EXISTS eora_guard_shift_delete();
            DROP TRIGGER IF EXISTS delete_guard ON shift_calendars;
            DROP TRIGGER IF EXISTS delete_guard ON business_calendars;
            DROP FUNCTION IF EXISTS eora_guard_calendar_version_delete();
            DROP TABLE IF EXISTS shift_weekday_times;
            DROP FUNCTION IF EXISTS eora_guard_shift_weekday_times();
            DROP FUNCTION IF EXISTS eora_calendar_scope_today(uuid, uuid);
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

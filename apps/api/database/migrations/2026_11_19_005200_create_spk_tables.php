<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SPK per Line — daily production dispatch (doc 38, D-138), slice 1. One SPK per plant, line (work center) and
 * production date; its lines point to a released / in-production WO, one operation of the WO's routing snapshot on that
 * line, a machine of the line, a planned quantity, a priority and an optional shift. An SPK never posts stock.
 * DRAFT → RELEASED → CLOSED, DRAFT → CANCELLED; a revision of a RELEASED SPK is a new DRAFT that supersedes it on release.
 */
return new class extends Migration
{
    private const array RIGHTS = [
        ['production.spk.view', 'view', ['PPC', 'PRL', 'CSA', 'AUD']],
        ['production.spk.create', 'create', ['PPC']],
        ['production.spk.release', 'post', ['PPC', 'CSA']],
        ['production.spk.close', 'approve', ['PPC']],
    ];

    public function up(): void
    {
        foreach (self::RIGHTS as [$code, $action, $roles]) {
            DB::insert('insert into permissions (code, module, action, sensitive) values (?, ?, ?, false) on conflict do nothing', [$code, 'production', $action]);
            foreach ($roles as $role) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$role, $code]);
            }
        }
        DB::unprepared(<<<'SQL'
            CREATE TABLE spk_headers (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              work_center_id uuid NOT NULL,
              production_date date NOT NULL,
              revision integer NOT NULL DEFAULT 1 CHECK (revision BETWEEN 1 AND 99),
              previous_id uuid,
              spk_no text CHECK (spk_no ~ '^SPK-[A-Z0-9._-]{1,20}-[0-9]{6}-[0-9]{4}$'),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'RELEASED', 'CLOSED', 'CANCELLED', 'SUPERSEDED')),
              reference text CHECK (length(btrim(reference)) BETWEEN 1 AND 60),
              note text CHECK (length(note) <= 500),
              revision_reason text CHECK (length(btrim(revision_reason)) BETWEEN 3 AND 500),
              released_by uuid, released_at timestamptz,
              closed_by uuid, closed_at timestamptz,
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, spk_no, revision),
              CHECK ((revision = 1) = (previous_id IS NULL)),
              CHECK ((revision = 1) = (revision_reason IS NULL)),
              CHECK (status IN ('DRAFT', 'CANCELLED') OR (spk_no IS NOT NULL AND released_at IS NOT NULL)),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants(tenant_id, id),
              FOREIGN KEY (tenant_id, work_center_id) REFERENCES work_centers(tenant_id, id),
              FOREIGN KEY (tenant_id, previous_id) REFERENCES spk_headers(tenant_id, id)
            );
            -- one draft and one released SPK per line and day: a revision is drafted while its released SPK is in force
            CREATE UNIQUE INDEX spk_one_draft ON spk_headers (tenant_id, plant_id, work_center_id, production_date) WHERE status = 'DRAFT';
            CREATE UNIQUE INDEX spk_one_released ON spk_headers (tenant_id, plant_id, work_center_id, production_date) WHERE status = 'RELEASED';
            CREATE UNIQUE INDEX spk_one_revision ON spk_headers (tenant_id, previous_id) WHERE previous_id IS NOT NULL AND status <> 'CANCELLED';
            CREATE INDEX spk_headers_date_idx ON spk_headers (tenant_id, plant_id, production_date DESC);

            CREATE TABLE spk_lines (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              spk_id uuid NOT NULL,
              line_no integer NOT NULL CHECK (line_no BETWEEN 1 AND 999),
              work_order_id uuid NOT NULL,
              routing_operation_id uuid NOT NULL,
              machine_id uuid NOT NULL,
              shift_id uuid,
              planned_qty numeric(18,6) NOT NULL CHECK (planned_qty > 0),
              priority integer NOT NULL DEFAULT 1 CHECK (priority BETWEEN 1 AND 99),
              setup_minutes numeric(8,2) NOT NULL DEFAULT 0 CHECK (setup_minutes BETWEEN 0 AND 1440),
              qc_sample_minutes numeric(8,2) NOT NULL DEFAULT 5 CHECK (qc_sample_minutes BETWEEN 0 AND 1440),
              note text CHECK (length(note) <= 300),
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, spk_id, line_no),
              FOREIGN KEY (tenant_id, spk_id) REFERENCES spk_headers(tenant_id, id),
              FOREIGN KEY (tenant_id, work_order_id) REFERENCES work_orders(tenant_id, id),
              FOREIGN KEY (tenant_id, routing_operation_id) REFERENCES routing_operations(tenant_id, id),
              FOREIGN KEY (tenant_id, machine_id) REFERENCES machines(tenant_id, id),
              FOREIGN KEY (tenant_id, shift_id) REFERENCES shifts(tenant_id, id)
            );
            CREATE INDEX spk_lines_wo_idx ON spk_lines (tenant_id, work_order_id);

            ALTER TABLE spk_headers ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            ALTER TABLE spk_lines ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON spk_headers TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            CREATE POLICY tenant_isolation ON spk_lines TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON spk_headers TO eora_app;
            GRANT UPDATE (spk_no, status, reference, note, released_by, released_at, closed_by, closed_at) ON spk_headers TO eora_app;
            GRANT SELECT, INSERT, DELETE ON spk_lines TO eora_app;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON spk_headers FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON spk_lines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- who writes an SPK and how its status moves (doc 38 §3, §5)
            CREATE OR REPLACE FUNCTION eora_guard_spk_header() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE wc record; prev record;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.spk_no IS NOT NULL OR NOT public.eora_actor_may('production.spk.create', NEW.plant_id) THEN
                  RAISE EXCEPTION 'an SPK starts as a draft written by a planner of its plant' USING ERRCODE = '42501';
                END IF;
                SELECT w.plant_id, w.status INTO wc FROM public.work_centers w WHERE w.tenant_id = NEW.tenant_id AND w.id = NEW.work_center_id;
                IF wc.plant_id IS DISTINCT FROM NEW.plant_id OR wc.status <> 'ACTIVE' THEN
                  RAISE EXCEPTION 'an SPK is for an active line of its plant' USING ERRCODE = '23514';
                END IF;
                IF NEW.previous_id IS NOT NULL THEN
                  SELECT h.plant_id, h.work_center_id, h.production_date, h.status, h.revision, h.spk_no INTO prev
                    FROM public.spk_headers h WHERE h.tenant_id = NEW.tenant_id AND h.id = NEW.previous_id;
                  IF prev.status <> 'RELEASED' OR prev.plant_id IS DISTINCT FROM NEW.plant_id OR prev.work_center_id IS DISTINCT FROM NEW.work_center_id
                     OR prev.production_date IS DISTINCT FROM NEW.production_date OR NEW.revision <> prev.revision + 1 THEN
                    RAISE EXCEPTION 'a revision follows the released SPK of the same line and day' USING ERRCODE = '23514';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.spk_no IS DISTINCT FROM OLD.spk_no AND (OLD.spk_no IS NOT NULL OR NOT (OLD.status = 'DRAFT' AND NEW.status = 'RELEASED')) THEN
                RAISE EXCEPTION 'an SPK keeps its number' USING ERRCODE = '23514';
              END IF;
              -- who released or closed it, and when, is written once by that transition, as the signed actor, now
              IF (NEW.released_by, NEW.released_at) IS DISTINCT FROM (OLD.released_by, OLD.released_at)
                 AND NOT (OLD.status = 'DRAFT' AND NEW.status = 'RELEASED' AND NEW.released_by = (SELECT c.actor FROM ctx_api.context() c)
                          AND NEW.released_at = pg_catalog.now()) THEN
                RAISE EXCEPTION 'an SPK records its release once, by the releasing planner' USING ERRCODE = '23514';
              END IF;
              IF (NEW.closed_by, NEW.closed_at) IS DISTINCT FROM (OLD.closed_by, OLD.closed_at)
                 AND NOT (OLD.status = 'RELEASED' AND NEW.status = 'CLOSED' AND NEW.closed_by = (SELECT c.actor FROM ctx_api.context() c)
                          AND NEW.closed_at = pg_catalog.now()) THEN
                RAISE EXCEPTION 'an SPK records its closing once, by the closing planner' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('RELEASED', 'CANCELLED'))
                     OR (OLD.status = 'RELEASED' AND NEW.status IN ('CLOSED', 'SUPERSEDED'))) THEN
                  RAISE EXCEPTION 'an SPK moves draft → released → closed (or superseded by its revision)' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'RELEASED' AND NOT public.eora_actor_may('production.spk.release', NEW.plant_id) THEN
                  RAISE EXCEPTION 'an SPK is released by a planner of its plant' USING ERRCODE = '42501';
                END IF;
                IF NEW.status = 'RELEASED' AND EXISTS (SELECT 1 FROM public.spk_lines l JOIN public.work_orders w ON w.tenant_id = l.tenant_id AND w.id = l.work_order_id
                     WHERE l.tenant_id = NEW.tenant_id AND l.spk_id = NEW.id AND w.status NOT IN ('RELEASED', 'IN_PROGRESS')) THEN
                  RAISE EXCEPTION 'an SPK is released only while its work orders are released or in production' USING ERRCODE = '23514';
                END IF;
                IF NEW.status IN ('CLOSED', 'SUPERSEDED', 'CANCELLED') AND NOT (public.eora_actor_may('production.spk.close', NEW.plant_id)
                     OR public.eora_actor_may('production.spk.release', NEW.plant_id) OR public.eora_actor_may('production.spk.create', NEW.plant_id)) THEN
                  RAISE EXCEPTION 'an SPK is closed by a planner of its plant' USING ERRCODE = '42501';
                END IF;
                IF NEW.status = 'SUPERSEDED' AND NOT EXISTS (SELECT 1 FROM public.spk_headers h WHERE h.tenant_id = NEW.tenant_id
                     AND h.previous_id = NEW.id AND h.status = 'DRAFT') THEN
                  RAISE EXCEPTION 'an SPK is superseded only by the release of its revision' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status <> 'DRAFT' AND (NEW.reference, NEW.note) IS DISTINCT FROM (OLD.reference, OLD.note) THEN
                RAISE EXCEPTION 'a released SPK changes through a revision' USING ERRCODE = '23514';
              ELSIF OLD.status = 'DRAFT' AND NOT public.eora_actor_may('production.spk.create', NEW.plant_id) THEN
                -- any other change of a draft (its text, or the version bump of a line edit) is the planner's
                RAISE EXCEPTION 'an SPK draft is edited by a planner of its plant' USING ERRCODE = '42501';
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_spk_header() FROM PUBLIC;
            CREATE TRIGGER guard_header BEFORE INSERT OR UPDATE ON spk_headers FOR EACH ROW EXECUTE FUNCTION eora_guard_spk_header();

            -- an SPK line plans a released WO's operation on a machine of the SPK's line (doc 38 §4)
            CREATE OR REPLACE FUNCTION eora_guard_spk_line() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE h record; w record; o record; m record; cal uuid; target uuid := COALESCE(NEW.spk_id, OLD.spk_id);
            BEGIN
              SELECT x.status, x.plant_id, x.work_center_id, x.production_date INTO h FROM public.spk_headers x WHERE x.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND x.id = target;
              IF h.status <> 'DRAFT' THEN
                RAISE EXCEPTION 'the lines of a released SPK change through a revision' USING ERRCODE = '23514';
              END IF;
              IF NOT public.eora_actor_may('production.spk.create', h.plant_id) THEN
                RAISE EXCEPTION 'SPK lines are written by a planner of its plant' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'DELETE' THEN
                RETURN OLD;
              END IF;
              SELECT x.plant_id, x.status, x.routing_version_id INTO w FROM public.work_orders x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.work_order_id;
              IF w.plant_id IS DISTINCT FROM h.plant_id OR w.status NOT IN ('RELEASED', 'IN_PROGRESS') THEN
                RAISE EXCEPTION 'an SPK line plans a released work order of its plant' USING ERRCODE = '23514';
              END IF;
              SELECT x.routing_version_id, x.work_center_id INTO o FROM public.routing_operations x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.routing_operation_id;
              IF o.routing_version_id IS DISTINCT FROM w.routing_version_id OR o.work_center_id IS DISTINCT FROM h.work_center_id THEN
                RAISE EXCEPTION 'an SPK line is an operation of the work order''s routing on the SPK''s line' USING ERRCODE = '23514';
              END IF;
              SELECT x.work_center_id, x.status INTO m FROM public.machines x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.machine_id;
              IF m.work_center_id IS DISTINCT FROM h.work_center_id OR m.status <> 'ACTIVE' THEN
                RAISE EXCEPTION 'an SPK line runs on an active machine of the SPK''s line' USING ERRCODE = '23514';
              END IF;
              IF NEW.shift_id IS NOT NULL THEN
                -- the shift calendar in force on the SPK's day: the plant's own before the tenant's, latest version first
                -- (BusinessCalendar::effectiveVersion), so one physical shift never counts twice
                SELECT c.id INTO cal FROM public.shift_calendars c
                 WHERE c.tenant_id = NEW.tenant_id AND (c.plant_id = h.plant_id OR c.plant_id IS NULL) AND c.effective_from <= h.production_date
                 ORDER BY (c.plant_id IS NULL), c.effective_from DESC LIMIT 1;
                IF cal IS NULL OR NOT EXISTS (SELECT 1 FROM public.shifts x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.shift_id AND x.shift_calendar_id = cal) THEN
                  RAISE EXCEPTION 'an SPK line uses a shift of the calendar in force on its day' USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END $fn$;
            REVOKE ALL ON FUNCTION eora_guard_spk_line() FROM PUBLIC;
            CREATE TRIGGER guard_line BEFORE INSERT OR UPDATE OR DELETE ON spk_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_spk_line();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE spk_lines;
            DROP TABLE spk_headers;
            DROP FUNCTION eora_guard_spk_line();
            DROP FUNCTION eora_guard_spk_header();
            SQL);
        foreach (self::RIGHTS as [$code]) {
            DB::delete('delete from role_template_permissions where permission_code = ?', [$code]);
            try {
                DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [$code]));
            } catch (QueryException $e) {
                if ($e->getCode() !== '23503') {
                    throw $e;
                }
            }
        }
    }
};

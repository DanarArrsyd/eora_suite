<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M5 step 2 (doc 34 §1.2, D-122, D-038, D-040, BR-DEM-08, BR-DEM-11): forecast versions and the revision of a firm
 * schedule line.
 *
 * - `forecast_versions` per customer + plant (`FC-<plant>-<year>-NNNN`): PPC drafts (`demand.forecast.create`) and
 *   activates (`demand.forecast.post`); activating supersedes the active version of that customer and plant.
 *   `forecast_lines`: a quantity per customer part per ISO week (Monday). Consumption by firm demand is derived on read.
 * - Revision: a FIRM / PARTIALLY_FULFILLED schedule line becomes SUPERSEDED (by a planner of its plant, with nothing
 *   allocated or staged, pointing at its first successor); new FIRM lines take `revision + 1`. `schedule_reallocations`
 *   records where the old line's outstanding went (from, to, qty, reason, actor).
 * - A shipment of a line revised later is still reversed (D-038 (9)): the reversal may bring its goods back to staging
 *   for the superseded line, from where they are put back.
 *
 * Existing tenants receive the template permissions through `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['demand.forecast.view', 'view', false, ['PPC', 'CSA', 'DLV', 'AUD']],
        ['demand.forecast.create', 'create', false, ['PPC']],
        ['demand.forecast.post', 'post', false, ['PPC']],
    ];

    /** [function, original text, replacement] patched in place (asserted), restored by down(). */
    private const array PATCHES = [
        ['eora_guard_delivery_leg()',
            "IF loc = 'STAGING' AND NEW.qty_signed > 0 AND s.status NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN",
            "IF loc = 'STAGING' AND NEW.qty_signed > 0 AND s.status NOT IN ('FIRM', 'PARTIALLY_FULFILLED')
                 AND NOT (s.status = 'SUPERSEDED' AND EXISTS (SELECT 1 FROM public.inventory_documents rd WHERE rd.tenant_id = NEW.tenant_id AND rd.id = NEW.document_id AND rd.doc_type = 'REVERSAL')) THEN", ],
        ['eora_check_staged()',
            "AND (SELECT sl.status FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id) NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN",
            "AND (SELECT sl.status FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id) NOT IN ('FIRM', 'PARTIALLY_FULFILLED')
                 AND NOT ((SELECT sl.status FROM public.delivery_schedule_lines sl WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id) = 'SUPERSEDED'
                          AND EXISTS (SELECT 1 FROM public.inventory_documents rd WHERE rd.tenant_id = NEW.tenant_id AND rd.id = NEW.document_id AND rd.doc_type = 'REVERSAL')) THEN", ],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$code, $action, $sensitive, $templates]) {
            DB::insert("insert into permissions (code, module, action, sensitive) values (?, 'demand', ?, ?) on conflict (code) do nothing", [$code, $action, $sensitive]);
            foreach ($templates as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE forecast_versions (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              customer_id uuid NOT NULL,
              forecast_no text NOT NULL CHECK (forecast_no ~ '^FC-[A-Z0-9_-]+-[0-9]{4}-[0-9]{4,}$'),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'ACTIVE', 'SUPERSEDED')),
              note text CHECK (length(note) BETWEEN 1 AND 500),
              activated_at timestamptz,
              activated_by uuid,
              superseded_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, forecast_no),
              UNIQUE (tenant_id, id, customer_id, plant_id),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, customer_id) REFERENCES customers (tenant_id, id),
              CHECK ((status = 'DRAFT') = (activated_at IS NULL) AND (activated_at IS NULL) = (activated_by IS NULL)),
              CHECK ((status = 'SUPERSEDED') = (superseded_at IS NOT NULL))
            );
            -- one active version per customer and plant
            CREATE UNIQUE INDEX forecast_versions_active_uq ON forecast_versions (tenant_id, customer_id, plant_id) WHERE status = 'ACTIVE';
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON forecast_versions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE forecast_versions ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON forecast_versions TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, UPDATE ON forecast_versions TO eora_app;

            CREATE TABLE forecast_lines (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              forecast_version_id uuid NOT NULL,
              customer_id uuid NOT NULL,
              plant_id uuid NOT NULL,
              customer_item_id uuid NOT NULL,
              item_id uuid NOT NULL,
              week_start date NOT NULL CHECK (extract(isodow FROM week_start) = 1),
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, forecast_version_id, customer_item_id, week_start),
              -- a line is of its version's customer and plant, and of a part of that customer
              FOREIGN KEY (tenant_id, forecast_version_id, customer_id, plant_id) REFERENCES forecast_versions (tenant_id, id, customer_id, plant_id) ON DELETE CASCADE,
              FOREIGN KEY (tenant_id, customer_item_id) REFERENCES customer_items (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id)
            );
            CREATE INDEX forecast_lines_match_idx ON forecast_lines (tenant_id, plant_id, customer_id, item_id, week_start);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON forecast_lines FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE forecast_lines ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON forecast_lines TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT, DELETE ON forecast_lines TO eora_app;

            -- who writes a forecast, and what an active or superseded version keeps
            CREATE OR REPLACE FUNCTION eora_guard_forecast() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              v record;
            BEGIN
              IF TG_TABLE_NAME = 'forecast_lines' THEN
                SELECT f.status, f.plant_id INTO v FROM public.forecast_versions f
                 WHERE f.tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND f.id = COALESCE(NEW.forecast_version_id, OLD.forecast_version_id);
                IF v.status IS DISTINCT FROM 'DRAFT' THEN
                  RAISE EXCEPTION 'only a draft forecast changes its lines' USING ERRCODE = '23514';
                END IF;
                IF NOT public.eora_actor_may('demand.forecast.create', v.plant_id) THEN
                  RAISE EXCEPTION 'a forecast is written by a planner of the plant' USING ERRCODE = '42501';
                END IF;
                IF TG_OP = 'INSERT' AND NOT EXISTS (SELECT 1 FROM public.customer_items ci WHERE ci.tenant_id = NEW.tenant_id AND ci.id = NEW.customer_item_id
                                                      AND ci.customer_id = NEW.customer_id AND ci.item_id = NEW.item_id) THEN
                  RAISE EXCEPTION 'a forecast line is a part of the version''s customer' USING ERRCODE = '23514';
                END IF;
                RETURN COALESCE(NEW, OLD);
              END IF;
              IF NOT public.eora_actor_may('demand.forecast.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'a forecast is written by a planner of the plant' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' THEN
                  RAISE EXCEPTION 'a forecast version starts as a draft' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.plant_id, NEW.customer_id, NEW.forecast_no) IS DISTINCT FROM (OLD.plant_id, OLD.customer_id, OLD.forecast_no) THEN
                RAISE EXCEPTION 'a forecast version keeps its customer, plant and number' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'SUPERSEDED' THEN
                RAISE EXCEPTION 'a superseded forecast version is history' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                IF OLD.status = 'DRAFT' AND NEW.status = 'ACTIVE' THEN
                  IF NEW.activated_by IS DISTINCT FROM a OR NOT public.eora_actor_may('demand.forecast.post', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a forecast is activated by a planner of the plant' USING ERRCODE = '42501';
                  END IF;
                  IF NOT EXISTS (SELECT 1 FROM public.forecast_lines l WHERE l.tenant_id = NEW.tenant_id AND l.forecast_version_id = NEW.id) THEN
                    RAISE EXCEPTION 'a forecast version is activated with at least one line' USING ERRCODE = '23514';
                  END IF;
                ELSIF NOT (OLD.status = 'ACTIVE' AND NEW.status = 'SUPERSEDED') THEN
                  RAISE EXCEPTION 'a forecast goes DRAFT → ACTIVE → SUPERSEDED' USING ERRCODE = '23514';
                END IF;
              ELSIF (NEW.activated_at, NEW.activated_by) IS DISTINCT FROM (OLD.activated_at, OLD.activated_by) THEN
                RAISE EXCEPTION 'an activation is history' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_forecast() FROM PUBLIC;
            CREATE TRIGGER guard_forecast BEFORE INSERT OR UPDATE ON forecast_versions FOR EACH ROW EXECUTE FUNCTION eora_guard_forecast();
            CREATE TRIGGER guard_forecast BEFORE INSERT OR DELETE ON forecast_lines FOR EACH ROW EXECUTE FUNCTION eora_guard_forecast();

            -- where the outstanding of a revised schedule line went (BR-DEM-11)
            CREATE TABLE schedule_reallocations (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              from_line_id uuid NOT NULL,
              to_line_id uuid NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, from_line_id) REFERENCES delivery_schedule_lines (tenant_id, id),
              FOREIGN KEY (tenant_id, to_line_id) REFERENCES delivery_schedule_lines (tenant_id, id)
            );
            CREATE INDEX schedule_reallocations_from_idx ON schedule_reallocations (tenant_id, from_line_id);
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON schedule_reallocations FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            ALTER TABLE schedule_reallocations ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON schedule_reallocations TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON schedule_reallocations TO eora_app;

            -- a reallocation maps a superseded line to a successor of the same order line, within its outstanding
            CREATE OR REPLACE FUNCTION eora_guard_schedule_reallocation() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              f record;
              t record;
              delivered numeric;
            BEGIN
              SELECT s.so_line_id, s.status, s.required_qty, s.plant_id INTO f FROM public.delivery_schedule_lines s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.from_line_id;
              SELECT s.so_line_id, s.status, s.revision INTO t FROM public.delivery_schedule_lines s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.to_line_id;
              IF f.status IS DISTINCT FROM 'SUPERSEDED' OR t.so_line_id IS DISTINCT FROM f.so_line_id OR t.status <> 'FIRM' THEN
                RAISE EXCEPTION 'outstanding moves from a superseded line to a firm line of the same order line' USING ERRCODE = '23514';
              END IF;
              IF NOT public.eora_actor_may('demand.sales_order.post', f.plant_id) THEN
                RAISE EXCEPTION 'a schedule line is revised by a planner of its plant' USING ERRCODE = '42501';
              END IF;
              delivered := coalesce((SELECT sum(m.qty_signed) FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                                      WHERE m.tenant_id = NEW.tenant_id AND m.schedule_line_id = NEW.from_line_id AND l.location_type = 'V_CUSTOMER'), 0);
              IF NEW.qty + coalesce((SELECT sum(r.qty) FROM public.schedule_reallocations r WHERE r.tenant_id = NEW.tenant_id AND r.from_line_id = NEW.from_line_id), 0)
                 > greatest(f.required_qty - delivered, 0) THEN
                RAISE EXCEPTION 'no more is re-allocated than the line had outstanding' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_schedule_reallocation() FROM PUBLIC;
            CREATE TRIGGER guard_schedule_reallocation BEFORE INSERT ON schedule_reallocations FOR EACH ROW EXECUTE FUNCTION eora_guard_schedule_reallocation();

            -- a firm line is superseded by a planner of its plant, by a newer revision, with nothing allocated or staged
            CREATE OR REPLACE FUNCTION eora_guard_schedule_revision() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF OLD.status NOT IN ('FIRM', 'PARTIALLY_FULFILLED') THEN
                RAISE EXCEPTION 'a firm schedule line is revised' USING ERRCODE = '23514';
              END IF;
              IF NOT public.eora_actor_may('demand.sales_order.post', NEW.plant_id) THEN
                RAISE EXCEPTION 'a schedule line is revised by a planner of its plant' USING ERRCODE = '42501';
              END IF;
              IF NOT EXISTS (SELECT 1 FROM public.delivery_schedule_lines s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.superseded_by
                               AND s.so_line_id = NEW.so_line_id AND s.revision > NEW.revision AND s.status = 'FIRM') THEN
                RAISE EXCEPTION 'a schedule line is superseded by a newer revision of its order line' USING ERRCODE = '23514';
              END IF;
              IF public.eora_schedule_line_committed(NEW.tenant_id, NEW.id)
                 > coalesce((SELECT sum(m.qty_signed) FROM public.inventory_movements m JOIN public.locations l ON l.tenant_id = m.tenant_id AND l.id = m.location_id
                              WHERE m.tenant_id = NEW.tenant_id AND m.schedule_line_id = NEW.id AND l.location_type = 'V_CUSTOMER'), 0) THEN
                RAISE EXCEPTION 'release the allocation and put back what is staged before revising the line' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_schedule_revision() FROM PUBLIC;
            CREATE TRIGGER guard_schedule_revision BEFORE UPDATE ON delivery_schedule_lines FOR EACH ROW
              WHEN (NEW.status = 'SUPERSEDED' AND OLD.status IS DISTINCT FROM 'SUPERSEDED') EXECUTE FUNCTION eora_guard_schedule_revision();
            GRANT UPDATE (superseded_by) ON delivery_schedule_lines TO eora_app;
            SQL);

        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            REVOKE UPDATE (superseded_by) ON delivery_schedule_lines FROM eora_app;
            DROP TRIGGER IF EXISTS guard_schedule_revision ON delivery_schedule_lines;
            DROP FUNCTION IF EXISTS eora_guard_schedule_revision();
            DROP TABLE IF EXISTS schedule_reallocations;
            DROP FUNCTION IF EXISTS eora_guard_schedule_reallocation();
            DROP TABLE IF EXISTS forecast_lines;
            DROP TABLE IF EXISTS forecast_versions;
            DROP FUNCTION IF EXISTS eora_guard_forecast();
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

    /** Replaces each original text in its function's definition (or back), asserting the text is there exactly once. */
    private function patch(bool $back): void
    {
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definition = (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definition, $find) !== 1) {
                throw new RuntimeException("{$function}: the text to patch is not there exactly once.");
            }
            // the definition comes from the database itself (pg_get_functiondef), changed only by the constant texts above
            DB::connection()->getPdo()->exec(str_replace($find, $replace, $definition));
        }
    }
};

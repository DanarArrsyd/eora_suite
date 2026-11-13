<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 2 (doc 33 §1.2, D-107, D-109, G-MPS-6, BR-PLN-01, doc 16 §1): manual Work Orders outside MPS. Owner rules of
 * 2026-11-12: PPC drafts a WO with a fixed planning-exception reason and asks for release; a second person with
 * `planning.wo.approve` (CSA template; APR starts empty, D-068) releases or returns it, never the requester. Release
 * snapshots the approved BOM and primary routing versions in force (required) and, per material line, the validated
 * conversion profile version when there is one (not blocking). Pegging to a schedule line is optional and never changes
 * demand; the WO quantity is free (D-034).
 *
 * The status list carries the later states of doc 16 §1 (runs, hold, completion, close) so later steps add no CHECK change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_orders (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              plant_id uuid NOT NULL,
              wo_no text NOT NULL CHECK (wo_no ~ '^[A-Z0-9][A-Z0-9/_-]{0,39}$'),
              item_id uuid NOT NULL,
              uom_id uuid NOT NULL,
              qty numeric(20, 6) NOT NULL CHECK (qty > 0),
              due_date date NOT NULL,
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'RELEASED', 'IN_PROGRESS', 'ON_HOLD',
                'PRODUCTION_COMPLETE', 'CLOSED', 'CANCELLED')),
              -- G-MPS-6: every M4 WO is outside an approved MPS and says why
              reason_code text NOT NULL CHECK (reason_code IN ('URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER')),
              note text CHECK (length(note) BETWEEN 1 AND 500 AND note = btrim(note)),
              schedule_line_id uuid,
              -- BR-PLN-01 snapshot, written once at release
              bom_version_id uuid,
              routing_version_id uuid,
              -- the validated net weight of the item when there is one (not blocking, used by reconciliation in M5)
              item_weight_id uuid,
              submitted_by uuid,
              submitted_at timestamptz,
              released_by uuid,
              released_at timestamptz,
              return_reason text CHECK (length(return_reason) BETWEEN 3 AND 500 AND return_reason = btrim(return_reason)),
              cancelled_by uuid,
              cancelled_at timestamptz,
              cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 500 AND cancel_reason = btrim(cancel_reason)),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, plant_id, id),
              UNIQUE (tenant_id, wo_no),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, schedule_line_id) REFERENCES delivery_schedule_lines (tenant_id, id),
              FOREIGN KEY (tenant_id, bom_version_id, item_id) REFERENCES bom_versions (tenant_id, id, item_id),
              FOREIGN KEY (tenant_id, routing_version_id) REFERENCES routing_versions (tenant_id, id),
              FOREIGN KEY (tenant_id, item_weight_id) REFERENCES item_weights (tenant_id, id),
              CHECK ((status IN ('DRAFT', 'SUBMITTED', 'CANCELLED')) OR (bom_version_id IS NOT NULL AND routing_version_id IS NOT NULL
                     AND released_by IS NOT NULL AND released_at IS NOT NULL)),
              CHECK ((status = 'SUBMITTED') = (submitted_by IS NOT NULL AND submitted_at IS NOT NULL) OR status NOT IN ('DRAFT', 'SUBMITTED')),
              CHECK ((status = 'CANCELLED') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))
            );
            CREATE INDEX work_orders_list_idx ON work_orders (tenant_id, plant_id, created_at DESC);
            CREATE INDEX work_orders_item_idx ON work_orders (tenant_id, item_id, status);
            CREATE INDEX work_orders_schedule_idx ON work_orders (tenant_id, schedule_line_id) WHERE schedule_line_id IS NOT NULL;

            -- the material lines of the released WO: its BOM version's lines and the requirement for the WO quantity
            CREATE TABLE work_order_materials (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              work_order_id uuid NOT NULL,
              line_no integer NOT NULL CHECK (line_no BETWEEN 1 AND 9999),
              bom_line_id uuid NOT NULL,
              component_item_id uuid NOT NULL,
              uom_id uuid NOT NULL,
              gross_qty_per numeric(18, 6) NOT NULL CHECK (gross_qty_per > 0),
              scrap_allowance numeric(6, 5) NOT NULL CHECK (scrap_allowance >= 0 AND scrap_allowance < 1),
              required_qty numeric(20, 6) NOT NULL CHECK (required_qty > 0),
              conversion_profile_version_id uuid,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, work_order_id, line_no),
              FOREIGN KEY (tenant_id, work_order_id) REFERENCES work_orders (tenant_id, id),
              FOREIGN KEY (tenant_id, bom_line_id) REFERENCES bom_lines (tenant_id, id),
              FOREIGN KEY (tenant_id, component_item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, uom_id) REFERENCES uoms (tenant_id, id),
              FOREIGN KEY (tenant_id, conversion_profile_version_id) REFERENCES conversion_profile_versions (tenant_id, id)
            );

            -- does the signed actor hold $perm, through an active membership and a role in force, in a scope covering $plant?
            -- (the same reading as Authorizer::authorize; RLS keeps it inside the tenant)
            CREATE OR REPLACE FUNCTION eora_actor_may(perm text, plant uuid) RETURNS boolean LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT EXISTS (
                SELECT 1 FROM public.role_assignments ra
                  JOIN public.memberships m ON m.tenant_id = ra.tenant_id AND m.id = ra.membership_id AND m.status = 'ACTIVE'
                   AND m.user_id = (SELECT c.actor FROM ctx_api.context() c)
                  JOIN public.role_permissions rp ON rp.tenant_id = ra.tenant_id AND rp.role_id = ra.role_id AND rp.permission_code = perm
                  JOIN public.plants p ON p.tenant_id = ra.tenant_id AND p.id = plant
                 WHERE ra.revoked_at IS NULL AND ra.valid_from <= pg_catalog.now() AND (ra.valid_until IS NULL OR ra.valid_until > pg_catalog.now())
                   AND (ra.scope_type = 'TENANT' OR (ra.scope_type = 'COMPANY' AND ra.company_id = p.company_id) OR (ra.scope_type = 'PLANT' AND ra.plant_id = p.id))
              )
            $fn$;
            REVOKE ALL ON FUNCTION eora_actor_may(text, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_actor_may(text, uuid) TO eora_app;

            -- the plant's calendar date now, as BusinessCalendar::resolve() reads it (ADR-0012): the calendar version in force on
            -- the plant's local date (plant override before the tenant default), then that calendar's time zone
            CREATE OR REPLACE FUNCTION eora_plant_calendar_date(plant uuid) RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT (pg_catalog.now() AT TIME ZONE COALESCE(
                       (SELECT b.timezone FROM public.business_calendars b
                         WHERE (b.plant_id = p.id OR b.plant_id IS NULL) AND b.effective_from <= (pg_catalog.now() AT TIME ZONE p.timezone)::date
                         ORDER BY (b.plant_id IS NULL), b.effective_from DESC LIMIT 1),
                       p.timezone))::date
                FROM public.plants p WHERE p.id = plant
            $fn$;
            REVOKE ALL ON FUNCTION eora_plant_calendar_date(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_plant_calendar_date(uuid) TO eora_app;

            -- what a draft must be (as WorkOrderController::checked()): an active finished item counted in its base unit with that
            -- unit's decimals, a due date from the plant's today, a note when the reason is OTHER
            CREATE OR REPLACE FUNCTION eora_check_work_order_draft(w public.work_orders) RETURNS void LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM public.items i JOIN public.uoms u ON u.tenant_id = i.tenant_id AND u.id = i.base_uom_id
                              WHERE i.tenant_id = w.tenant_id AND i.id = w.item_id AND i.item_type = 'FINISHED' AND i.status = 'ACTIVE'
                                AND u.id = w.uom_id AND pg_catalog.scale(pg_catalog.trim_scale(w.qty)) <= u.decimals) THEN
                RAISE EXCEPTION 'a work order makes an active finished item, counted in its base unit and decimals' USING ERRCODE = '23514';
              END IF;
              IF w.due_date < public.eora_plant_calendar_date(w.plant_id) THEN
                RAISE EXCEPTION 'a work order is due today or later' USING ERRCODE = '23514';
              END IF;
              IF w.reason_code = 'OTHER' AND (w.note IS NULL OR pg_catalog.length(w.note) < 3) THEN
                RAISE EXCEPTION 'a work order outside MPS for another reason explains it' USING ERRCODE = '23514';
              END IF;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_work_order_draft(public.work_orders) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_check_work_order_draft(public.work_orders) TO eora_app;

            -- DRAFT ⇄ SUBMITTED → RELEASED by a second person; DRAFT/SUBMITTED/RELEASED → CANCELLED; nothing of what the WO
            -- asks for changes once it left the draft, and the release snapshot is written once
            CREATE OR REPLACE FUNCTION eora_guard_work_order() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              day date;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' THEN
                  RAISE EXCEPTION 'a work order starts as a draft' USING ERRCODE = '23514';
                END IF;
                IF NOT public.eora_actor_may('planning.wo.create', NEW.plant_id) THEN
                  RAISE EXCEPTION 'a work order is drafted by a planner of its plant' USING ERRCODE = '42501';
                END IF;
                PERFORM public.eora_check_work_order_draft(NEW);
                IF NEW.uom_id IS DISTINCT FROM (SELECT i.base_uom_id FROM public.items i WHERE i.tenant_id = NEW.tenant_id AND i.id = NEW.item_id) THEN
                  RAISE EXCEPTION 'a work order counts in its item''s base unit' USING ERRCODE = '23514';
                END IF;
                IF NEW.schedule_line_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM public.delivery_schedule_lines sl JOIN public.sales_order_lines l ON l.tenant_id = sl.tenant_id AND l.id = sl.so_line_id
                      WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id AND sl.plant_id = NEW.plant_id AND l.item_id = NEW.item_id
                        AND sl.status IN ('FIRM', 'PARTIALLY_FULFILLED')) THEN
                  RAISE EXCEPTION 'a work order serves a firm delivery of its item at its plant' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.plant_id, NEW.wo_no) IS DISTINCT FROM (OLD.plant_id, OLD.wo_no) THEN
                RAISE EXCEPTION 'a work order keeps its plant and number' USING ERRCODE = '23514';
              END IF;
              IF NEW.uom_id IS DISTINCT FROM (SELECT i.base_uom_id FROM public.items i WHERE i.tenant_id = NEW.tenant_id AND i.id = NEW.item_id) THEN
                RAISE EXCEPTION 'a work order counts in its item''s base unit' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IN ('DRAFT', 'SUBMITTED') AND OLD.status = 'DRAFT'
                 AND (NEW.status = 'SUBMITTED' OR (NEW.item_id, NEW.uom_id, NEW.qty, NEW.due_date, NEW.reason_code, NEW.note)
                      IS DISTINCT FROM (OLD.item_id, OLD.uom_id, OLD.qty, OLD.due_date, OLD.reason_code, OLD.note)) THEN
                PERFORM public.eora_check_work_order_draft(NEW);
              END IF;
              -- the peg is checked when it is set or the item changes on a draft, and when the release is asked; going back to
              -- draft after the delivery was cancelled stays possible, so the planner can change it
              IF NEW.schedule_line_id IS NOT NULL
                 AND ((OLD.status = 'DRAFT' AND NEW.status = 'DRAFT' AND (NEW.schedule_line_id, NEW.item_id) IS DISTINCT FROM (OLD.schedule_line_id, OLD.item_id))
                      OR (OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED'))
                 AND NOT EXISTS (
                   SELECT 1 FROM public.delivery_schedule_lines sl JOIN public.sales_order_lines l ON l.tenant_id = sl.tenant_id AND l.id = sl.so_line_id
                    WHERE sl.tenant_id = NEW.tenant_id AND sl.id = NEW.schedule_line_id AND sl.plant_id = NEW.plant_id AND l.item_id = NEW.item_id
                      AND sl.status IN ('FIRM', 'PARTIALLY_FULFILLED')) THEN
                RAISE EXCEPTION 'a work order serves a firm delivery of its item at its plant' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'DRAFT' AND NEW.status = 'DRAFT' AND NOT public.eora_actor_may('planning.wo.create', NEW.plant_id) THEN
                RAISE EXCEPTION 'a draft is edited by a planner of its plant' USING ERRCODE = '42501';
              END IF;
              -- asking for the release changes nothing of the draft: edits are made as a draft, by a planner allowed to edit
              IF OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED'
                 AND (NEW.item_id, NEW.uom_id, NEW.qty, NEW.due_date, NEW.reason_code, NEW.note, NEW.schedule_line_id)
                     IS DISTINCT FROM (OLD.item_id, OLD.uom_id, OLD.qty, OLD.due_date, OLD.reason_code, OLD.note, OLD.schedule_line_id) THEN
                RAISE EXCEPTION 'a release request carries no edits of the draft' USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT' AND (NEW.item_id, NEW.uom_id, NEW.qty, NEW.due_date, NEW.reason_code, NEW.note, NEW.schedule_line_id)
                 IS DISTINCT FROM (OLD.item_id, OLD.uom_id, OLD.qty, OLD.due_date, OLD.reason_code, OLD.note, OLD.schedule_line_id) THEN
                RAISE EXCEPTION 'a work order is edited only as a draft' USING ERRCODE = '23514';
              END IF;
              IF OLD.bom_version_id IS NOT NULL AND (NEW.bom_version_id, NEW.routing_version_id, NEW.item_weight_id, NEW.released_by, NEW.released_at)
                 IS DISTINCT FROM (OLD.bom_version_id, OLD.routing_version_id, OLD.item_weight_id, OLD.released_by, OLD.released_at) THEN
                RAISE EXCEPTION 'a release snapshot is history' USING ERRCODE = '23514';
              END IF;
              -- the release snapshot is written only by the release itself (SUBMITTED → RELEASED), and the return reason only on the
              -- way back to draft or when asking again
              IF NOT (OLD.status = 'SUBMITTED' AND NEW.status = 'RELEASED')
                 AND (NEW.bom_version_id, NEW.routing_version_id, NEW.item_weight_id, NEW.released_by, NEW.released_at)
                     IS DISTINCT FROM (OLD.bom_version_id, OLD.routing_version_id, OLD.item_weight_id, OLD.released_by, OLD.released_at) THEN
                RAISE EXCEPTION 'a release snapshot is written by the release only' USING ERRCODE = '23514';
              END IF;
              IF NOT ((OLD.status = 'SUBMITTED' AND NEW.status = 'DRAFT') OR (OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED'))
                 AND NEW.return_reason IS DISTINCT FROM OLD.return_reason THEN
                RAISE EXCEPTION 'a return reason is written when a request goes back to draft' USING ERRCODE = '23514';
              END IF;
              -- who asked for the release changes only by asking (DRAFT → SUBMITTED) or by the request going back to draft
              IF NOT ((OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED') OR (OLD.status = 'SUBMITTED' AND NEW.status = 'DRAFT'))
                 AND (NEW.submitted_by, NEW.submitted_at) IS DISTINCT FROM (OLD.submitted_by, OLD.submitted_at) THEN
                RAISE EXCEPTION 'the release request keeps its requester' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'SUBMITTED' AND NEW.status = 'DRAFT' AND (NEW.submitted_by IS NOT NULL OR NEW.submitted_at IS NOT NULL) THEN
                RAISE EXCEPTION 'a draft has no release request' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('CANCELLED', 'CLOSED') THEN
                RAISE EXCEPTION 'a cancelled or closed work order is history' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                IF OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED' THEN
                  IF NEW.submitted_by IS DISTINCT FROM a OR NOT public.eora_actor_may('planning.wo.submit', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a release is requested by a planner of its plant' USING ERRCODE = '42501';
                  END IF;
                ELSIF OLD.status = 'SUBMITTED' AND NEW.status = 'DRAFT' THEN
                  -- withdrawn by its requester, or returned by an approver of this plant
                  IF a IS DISTINCT FROM OLD.submitted_by AND NOT public.eora_actor_may('planning.wo.approve', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a release request is returned by an approver of its plant' USING ERRCODE = '42501';
                  END IF;
                ELSIF OLD.status = 'SUBMITTED' AND NEW.status = 'RELEASED' THEN
                  IF NEW.released_by IS DISTINCT FROM a OR a = OLD.submitted_by THEN
                    RAISE EXCEPTION 'a work order is released by a second person' USING ERRCODE = '42501';
                  END IF;
                  IF NOT public.eora_actor_may('planning.wo.approve', NEW.plant_id) THEN
                    RAISE EXCEPTION 'a work order is released by an approver of its plant' USING ERRCODE = '42501';
                  END IF;
                  -- BR-PLN-01: the snapshot is the approved BOM and primary routing (and validated net weight) in force today at the plant
                  day := public.eora_plant_calendar_date(NEW.plant_id);
                  IF NOT EXISTS (SELECT 1 FROM public.bom_versions b WHERE b.id = NEW.bom_version_id AND b.item_id = NEW.item_id
                                   AND b.status IN ('APPROVED', 'SUPERSEDED') AND b.effective_from <= day AND (b.effective_to IS NULL OR b.effective_to >= day))
                     OR NOT EXISTS (SELECT 1 FROM public.routing_versions v JOIN public.routings r ON r.tenant_id = v.tenant_id AND r.id = v.routing_id
                                     WHERE v.id = NEW.routing_version_id AND r.plant_id = NEW.plant_id AND r.item_id = NEW.item_id
                                       AND r.routing_kind = 'PRIMARY' AND r.status = 'ACTIVE' AND v.status IN ('APPROVED', 'SUPERSEDED')
                                       AND v.effective_from <= day AND (v.effective_to IS NULL OR v.effective_to >= day))
                     OR (NEW.item_weight_id IS NOT NULL AND NOT EXISTS (
                           SELECT 1 FROM public.item_weights w WHERE w.id = NEW.item_weight_id AND w.item_id = NEW.item_id
                              AND w.status IN ('VALIDATED', 'RETIRED') AND w.effective_from <= day AND (w.effective_to IS NULL OR w.effective_to >= day))) THEN
                    RAISE EXCEPTION 'a release snapshots the approved masters of the item in force today' USING ERRCODE = '23514';
                  END IF;
                ELSIF NEW.status = 'CANCELLED' AND OLD.status IN ('DRAFT', 'SUBMITTED', 'RELEASED') THEN
                  IF NEW.cancelled_by IS DISTINCT FROM a
                     OR NOT public.eora_actor_may(CASE WHEN OLD.status = 'RELEASED' THEN 'planning.wo.post' ELSE 'planning.wo.create' END, NEW.plant_id) THEN
                    RAISE EXCEPTION 'a work order is cancelled by a planner of its plant' USING ERRCODE = '42501';
                  END IF;
                ELSE
                  RAISE EXCEPTION 'work order status % cannot become %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_work_order() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON work_orders FOR EACH ROW EXECUTE FUNCTION eora_guard_work_order();

            -- the material lines are written once, by the release itself (same transaction: released_at = now()), as the
            -- snapshotted BOM version's lines × the WO quantity; nothing is added to a released snapshot afterwards
            CREATE OR REPLACE FUNCTION eora_guard_work_order_material() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM public.work_orders w JOIN public.bom_lines l ON l.tenant_id = w.tenant_id AND l.bom_version_id = w.bom_version_id
                 WHERE w.tenant_id = NEW.tenant_id AND w.id = NEW.work_order_id AND w.status = 'RELEASED' AND w.released_at = pg_catalog.now()
                   AND l.id = NEW.bom_line_id AND l.line_no = NEW.line_no AND l.component_item_id = NEW.component_item_id AND l.uom_id = NEW.uom_id
                   AND l.gross_qty_per = NEW.gross_qty_per AND l.scrap_allowance = NEW.scrap_allowance
                   AND NEW.required_qty = pg_catalog.round(w.qty * l.gross_qty_per, 6)
                   -- a conversion profile version, when given, is the line's profile's validated (or retired) one in force today
                   AND (NEW.conversion_profile_version_id IS NULL OR EXISTS (
                         SELECT 1 FROM public.conversion_profile_versions v
                          WHERE v.id = NEW.conversion_profile_version_id AND v.profile_id = l.conversion_profile_id
                            AND v.status IN ('VALIDATED', 'RETIRED') AND v.effective_from <= public.eora_plant_calendar_date(w.plant_id)
                            AND (v.effective_to IS NULL OR v.effective_to >= public.eora_plant_calendar_date(w.plant_id))))
              ) THEN
                RAISE EXCEPTION 'material lines are written by the release, from its BOM version' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_work_order_material() FROM PUBLIC;
            CREATE TRIGGER guard_snapshot BEFORE INSERT ON work_order_materials FOR EACH ROW EXECUTE FUNCTION eora_guard_work_order_material();

            -- a released WO carries every line of its BOM version: checked at commit (the release writes the lines after the status)
            CREATE OR REPLACE FUNCTION eora_check_work_order_materials() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.status = 'RELEASED' AND OLD.status IS DISTINCT FROM 'RELEASED' AND
                 (SELECT pg_catalog.count(*) FROM public.work_order_materials m WHERE m.tenant_id = NEW.tenant_id AND m.work_order_id = NEW.id)
                 IS DISTINCT FROM
                 (SELECT pg_catalog.count(*) FROM public.bom_lines l WHERE l.tenant_id = NEW.tenant_id AND l.bom_version_id = NEW.bom_version_id) THEN
                RAISE EXCEPTION 'a released work order carries every line of its BOM version' USING ERRCODE = '23514';
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_work_order_materials() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER check_materials AFTER UPDATE ON work_orders DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION eora_check_work_order_materials();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['work_orders', 'work_order_materials'] LOOP
                EXECUTE format('CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit()', t);
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            -- work orders are never deleted; their material lines are written once, at release
            GRANT UPDATE (item_id, uom_id, qty, due_date, status, reason_code, note, schedule_line_id, bom_version_id, routing_version_id, item_weight_id,
                          submitted_by, submitted_at, released_by, released_at, return_reason, cancelled_by, cancelled_at, cancel_reason) ON work_orders TO eora_app;
            SQL);

        // the second person who releases a manual WO (G-MPS-6, D-109): the CSA template; APR starts empty (D-068)
        DB::insert("insert into permissions (code, module, action, sensitive) values ('planning.wo.approve', 'planning', 'approve', true) on conflict (code) do nothing");
        DB::insert("insert into role_template_permissions (template_code, permission_code) values ('CSA', 'planning.wo.approve') on conflict do nothing");
    }

    public function down(): void
    {
        DB::delete("delete from role_template_permissions where permission_code = 'planning.wo.approve'");
        try {
            DB::transaction(fn () => DB::delete("delete from permissions where code = 'planning.wo.approve'"));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23503') {
                throw $e;
            }
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE work_order_materials;
            DROP TRIGGER IF EXISTS guard_lifecycle ON work_orders;
            DROP FUNCTION IF EXISTS eora_check_work_order_draft(public.work_orders);
            DROP TABLE work_orders;
            DROP FUNCTION IF EXISTS eora_guard_work_order();
            DROP FUNCTION IF EXISTS eora_guard_work_order_material();
            DROP FUNCTION IF EXISTS eora_actor_may(text, uuid);
            DROP FUNCTION IF EXISTS eora_check_work_order_materials();
            DROP FUNCTION IF EXISTS eora_plant_calendar_date(uuid);
            SQL);
    }
};

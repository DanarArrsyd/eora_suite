-- EORA routing approval (doc 26 §2.4a, D-058 D-B2-06/14/15, D-060, D-072). Run by bootstrap.sh as the bootstrap
-- superuser after 40_outbox.sql. Idempotent. The function is owned by the NOLOGIN eora_lifecycle_definer (member of no
-- role; column grants and tenant policies of its own, migration 001300) and executable by eora_app alone (definer
-- allowlist). It is the only path that makes a routing version APPROVED or SUPERSEDED: the
-- version guard trigger (migration 001300) checks current_user = 'eora_lifecycle_definer', which no login role can
-- become. Tables are created by migrations; plpgsql resolves them at call time.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET log_statement = 'none';

SET ROLE eora_lifecycle_definer;

-- Approves a SUBMITTED routing version for the signed actor and supersedes the version it replaces, in the caller's
-- transaction. Returns the id of the superseded version (or NULL). Error codes (SQLSTATE):
--   EO010 actor lacks master.routing.approve in a scope covering the routing's plant (or no trusted actor/tenant)
--   EO011 the actor prepared or edited this version (maker ≠ checker, SoD-08)
--   EO012 lifecycle: not SUBMITTED, starts in the past, or does not start after the version it would replace
--   EO013 content no longer valid: no operation, no standard yield, a machine outside its operation's work center,
--         or a tool that is not in the routing's plant or not linked to the item
--   P0002 unknown version (also another tenant's: RLS hides it)
-- Both audit events (routing_version.approved, routing_version.superseded) are written here, in the same transaction,
-- with before/after hashes (sha256 of the row's jsonb text); the audit trigger stamps the trusted actor.
CREATE OR REPLACE FUNCTION lifecycle_api.approve_routing_version(version_id uuid)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  ctx_tenant uuid;
  ctx_actor uuid;
  ver record;
  rt record;
  plant_company uuid;
  today date;
  previous record;
  superseded uuid;
  before_row jsonb;
  after_row jsonb;
BEGIN
  -- 1. trusted actor and tenant: the signed context only, never a setting or a parameter
  SELECT c.tenant, c.actor INTO ctx_tenant, ctx_actor FROM ctx_api.context() c;
  IF ctx_tenant IS NULL OR ctx_actor IS NULL THEN
    RAISE EXCEPTION 'no trusted actor' USING ERRCODE = 'EO010';
  END IF;
  SELECT (pg_catalog.now() AT TIME ZONE t.timezone)::date INTO today
    FROM public.tenants t WHERE t.id = ctx_tenant AND t.status = 'ACTIVE';
  IF today IS NULL THEN
    RAISE EXCEPTION 'tenant not active' USING ERRCODE = 'EO010';
  END IF;

  -- 5a. serialize: lock the routing, then the version (re-read after the lock)
  SELECT rv.routing_id INTO rt FROM public.routing_versions rv WHERE rv.id = version_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'unknown routing version' USING ERRCODE = 'P0002';
  END IF;
  SELECT r.id, r.plant_id, r.item_id INTO rt FROM public.routings r WHERE r.id = rt.routing_id FOR UPDATE;
  SELECT rv.* INTO ver FROM public.routing_versions rv WHERE rv.id = version_id FOR UPDATE;

  -- 2 + 3. active membership holding master.routing.approve in a scope covering the routing's plant
  SELECT p.company_id INTO plant_company FROM public.plants p WHERE p.id = rt.plant_id;
  IF NOT EXISTS (
    SELECT 1
      FROM public.role_assignments ra
      JOIN public.memberships m ON m.tenant_id = ra.tenant_id AND m.id = ra.membership_id AND m.status = 'ACTIVE' AND m.user_id = ctx_actor
      JOIN public.role_permissions rp ON rp.tenant_id = ra.tenant_id AND rp.role_id = ra.role_id AND rp.permission_code = 'master.routing.approve'
     WHERE ra.revoked_at IS NULL AND ra.valid_from <= pg_catalog.now() AND (ra.valid_until IS NULL OR ra.valid_until > pg_catalog.now())
       AND (ra.scope_type = 'TENANT'
            OR (ra.scope_type = 'COMPANY' AND ra.company_id = plant_company)
            OR (ra.scope_type = 'PLANT' AND ra.plant_id = rt.plant_id))
  ) THEN
    RAISE EXCEPTION 'not allowed to approve this routing' USING ERRCODE = 'EO010';
  END IF;

  -- 4. maker ≠ checker: neither the preparer nor anyone who edited, submitted, withdrew or reopened the version
  IF ver.prepared_by = ctx_actor OR EXISTS (
    SELECT 1 FROM public.routing_version_contributors rc WHERE rc.routing_version_id = ver.id AND rc.actor = ctx_actor
  ) THEN
    RAISE EXCEPTION 'an author of the version cannot approve it' USING ERRCODE = 'EO011';
  END IF;

  -- 5b. lifecycle validity (no retroactive effectivity, D-B2-14)
  IF ver.status <> 'SUBMITTED' THEN
    RAISE EXCEPTION 'only a submitted version can be approved' USING ERRCODE = 'EO012';
  END IF;
  IF ver.effective_from < today THEN
    RAISE EXCEPTION 'the version would take effect in the past' USING ERRCODE = 'EO012';
  END IF;

  -- content still valid now (machines and dies may have moved since the draft was saved)
  IF ver.standard_yield IS NULL THEN
    RAISE EXCEPTION 'standard yield missing' USING ERRCODE = 'EO013';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM public.routing_operations o WHERE o.routing_version_id = ver.id) THEN
    RAISE EXCEPTION 'a version needs at least one operation' USING ERRCODE = 'EO013';
  END IF;
  IF EXISTS (
    SELECT 1 FROM public.routing_operation_machines om
      JOIN public.routing_operations o ON o.id = om.operation_id
      JOIN public.machines mc ON mc.id = om.machine_id
     WHERE o.routing_version_id = ver.id AND mc.work_center_id <> o.work_center_id
  ) THEN
    RAISE EXCEPTION 'a machine is no longer in its operation''s work center' USING ERRCODE = 'EO013';
  END IF;
  IF EXISTS (
    SELECT 1 FROM public.routing_operation_tools ot
      JOIN public.routing_operations o ON o.id = ot.operation_id
      JOIN public.dies d ON d.id = ot.die_id
     WHERE o.routing_version_id = ver.id
       AND (d.plant_id <> rt.plant_id
            OR NOT EXISTS (SELECT 1 FROM public.die_items di WHERE di.die_id = d.id AND di.item_id = rt.item_id AND di.status = 'ACTIVE'))
  ) THEN
    RAISE EXCEPTION 'a tool is not in the routing''s plant or not linked to the item' USING ERRCODE = 'EO013';
  END IF;

  -- 5c + 6. supersede the approved version this one replaces, then approve (the exclusion constraint guards the result)
  FOR previous IN
    SELECT rv.id, rv.effective_from FROM public.routing_versions rv
     WHERE rv.routing_id = rt.id AND rv.status = 'APPROVED' AND rv.id <> ver.id
       AND (rv.effective_to IS NULL OR rv.effective_to >= ver.effective_from)
     FOR UPDATE
  LOOP
    IF previous.effective_from >= ver.effective_from THEN
      RAISE EXCEPTION 'the new version must start after the version it replaces' USING ERRCODE = 'EO012';
    END IF;
    SELECT pg_catalog.to_jsonb(rv) INTO before_row FROM public.routing_versions rv WHERE rv.id = previous.id;
    UPDATE public.routing_versions
       SET status = 'SUPERSEDED', effective_to = ver.effective_from - 1, superseded_by_version_id = ver.id, superseded_at = pg_catalog.now()
     WHERE id = previous.id;
    SELECT pg_catalog.to_jsonb(rv) INTO after_row FROM public.routing_versions rv WHERE rv.id = previous.id;
    INSERT INTO public.audit_events (tenant_id, action, entity_type, entity_id, plant_id, before_hash, after_hash, detail)
    VALUES (ctx_tenant, 'routing_version.superseded', 'routing_version', previous.id, rt.plant_id,
            pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(before_row::text, 'UTF8')), 'hex'),
            pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(after_row::text, 'UTF8')), 'hex'),
            pg_catalog.jsonb_build_object('supersededBy', ver.id));
    superseded := previous.id;
  END LOOP;

  SELECT pg_catalog.to_jsonb(rv) INTO before_row FROM public.routing_versions rv WHERE rv.id = ver.id;
  UPDATE public.routing_versions SET status = 'APPROVED', approved_by = ctx_actor, approved_at = pg_catalog.now() WHERE id = ver.id;
  SELECT pg_catalog.to_jsonb(rv) INTO after_row FROM public.routing_versions rv WHERE rv.id = ver.id;
  INSERT INTO public.audit_events (tenant_id, action, entity_type, entity_id, plant_id, before_hash, after_hash, detail)
  VALUES (ctx_tenant, 'routing_version.approved', 'routing_version', ver.id, rt.plant_id,
          pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(before_row::text, 'UTF8')), 'hex'),
          pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(after_row::text, 'UTF8')), 'hex'),
          CASE WHEN superseded IS NULL THEN '{}'::jsonb ELSE pg_catalog.jsonb_build_object('supersedes', superseded) END);

  RETURN superseded;
END
$fn$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA lifecycle_api FROM PUBLIC;

RESET ROLE;

-- the signed context (doc 25 §5.5): the definer reads actor and tenant itself
GRANT USAGE ON SCHEMA ctx_api TO eora_lifecycle_definer;
GRANT EXECUTE ON FUNCTION ctx_api.context() TO eora_lifecycle_definer;

GRANT USAGE ON SCHEMA lifecycle_api TO eora_app;
GRANT EXECUTE ON FUNCTION lifecycle_api.approve_routing_version(uuid) TO eora_app;

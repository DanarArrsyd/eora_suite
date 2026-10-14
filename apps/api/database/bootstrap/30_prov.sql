-- EORA tenant provisioning registry (doc 25 §2.5, D-B1-13, R-24). Run by bootstrap.sh as the bootstrap superuser after
-- 20_auth.sql. Idempotent. Owned by the NOLOGIN eora_prov_definer; no login role has USAGE on `prov`; the only surface
-- is `prov_api`, executable by eora_provisioner alone (schema guard allowlist).
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET log_statement = 'none';

SET ROLE eora_prov_definer;

CREATE TABLE IF NOT EXISTS prov.provisioning_requests (
  request_key text PRIMARY KEY CHECK (request_key ~ '^[0-9a-f]{64}$'),
  tenant_code text NOT NULL UNIQUE CHECK (tenant_code ~ '^[a-z0-9][a-z0-9-]{1,39}$'),
  tenant_id uuid NOT NULL UNIQUE,
  admin_email_hmac text NOT NULL CHECK (admin_email_hmac ~ '^[0-9a-f]{64}$'),
  admin_user_id uuid,
  admin_membership_id uuid,
  status text NOT NULL DEFAULT 'STARTED' CHECK (status IN ('STARTED', 'TENANT_CREATED', 'FAILED')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (status <> 'TENANT_CREATED' OR (admin_user_id IS NOT NULL AND admin_membership_id IS NOT NULL))
);

-- Step 0: registers (or finds) the request for a tenant code. Same key → the original tenant id and state; another
-- key for the same code → TENANT_CODE_TAKEN (SQLSTATE EP001), nothing else disclosed. The row lock serializes
-- concurrent runs until the caller's transaction ends.
CREATE OR REPLACE FUNCTION prov_api.begin_provisioning(request_key text, tenant_code text, admin_email_hmac text)
RETURNS TABLE (tenant_id uuid, status text, admin_membership_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  r prov.provisioning_requests%ROWTYPE;
BEGIN
  IF request_key !~ '^[0-9a-f]{64}$' OR tenant_code !~ '^[a-z0-9][a-z0-9-]{1,39}$' OR admin_email_hmac !~ '^[0-9a-f]{64}$' THEN
    RAISE EXCEPTION 'invalid arguments' USING ERRCODE = '22023';
  END IF;
  INSERT INTO prov.provisioning_requests (request_key, tenant_code, tenant_id, admin_email_hmac)
  VALUES (begin_provisioning.request_key, begin_provisioning.tenant_code, pg_catalog.uuidv7(), begin_provisioning.admin_email_hmac)
  ON CONFLICT DO NOTHING;
  SELECT * INTO r FROM prov.provisioning_requests p WHERE p.tenant_code = begin_provisioning.tenant_code FOR UPDATE;
  IF r.request_key IS DISTINCT FROM begin_provisioning.request_key THEN
    RAISE EXCEPTION 'TENANT_CODE_TAKEN' USING ERRCODE = 'EP001';
  END IF;
  RETURN QUERY SELECT r.tenant_id, r.status, r.admin_membership_id;
END
$fn$;

-- Step 2, first statement of the tenant transaction: locks the request; anything but STARTED → ALREADY_CREATED (EP002)
-- and the caller rolls back and takes the resend path.
CREATE OR REPLACE FUNCTION prov_api.lock_request(request_key text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  r prov.provisioning_requests%ROWTYPE;
BEGIN
  SELECT * INTO r FROM prov.provisioning_requests p WHERE p.request_key = lock_request.request_key FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'unknown provisioning request' USING ERRCODE = '22023';
  END IF;
  IF r.status <> 'STARTED' THEN
    RAISE EXCEPTION 'ALREADY_CREATED' USING ERRCODE = 'EP002';
  END IF;
  RETURN r.tenant_id;
END
$fn$;

-- Step 2, last statement of the tenant transaction: STARTED → TENANT_CREATED with the first administrator. Commits or
-- rolls back together with the tenant rows.
CREATE OR REPLACE FUNCTION prov_api.mark_tenant_created(request_key text, admin_user_id uuid, admin_membership_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  IF admin_user_id IS NULL OR admin_membership_id IS NULL THEN
    RAISE EXCEPTION 'invalid arguments' USING ERRCODE = '22023';
  END IF;
  UPDATE prov.provisioning_requests p
     SET status = 'TENANT_CREATED', admin_user_id = mark_tenant_created.admin_user_id,
         admin_membership_id = mark_tenant_created.admin_membership_id, updated_at = pg_catalog.now()
   WHERE p.request_key = mark_tenant_created.request_key AND p.status = 'STARTED';
  IF NOT FOUND THEN
    RAISE EXCEPTION 'ALREADY_CREATED' USING ERRCODE = 'EP002';
  END IF;
END
$fn$;

-- Lookup bound to both the code and the key; never lists tenants, never returns an e-mail.
CREATE OR REPLACE FUNCTION prov_api.provision_lookup(tenant_code text, request_key text)
RETURNS TABLE (tenant_id uuid, status text, admin_membership_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path = '' STABLE AS $fn$
  SELECT p.tenant_id, p.status, p.admin_membership_id FROM prov.provisioning_requests p
   WHERE p.tenant_code = provision_lookup.tenant_code AND p.request_key = provision_lookup.request_key;
$fn$;

-- Tenants whose provisioning finished (operator maintenance, e.g. `eora:tenants:sync` after a catalogue migration, D-072).
-- Ids only; every further read or write happens in that tenant's signed context.
CREATE OR REPLACE FUNCTION prov_api.provisioned_tenants()
RETURNS TABLE (tenant_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path = '' STABLE AS $fn$
  SELECT p.tenant_id FROM prov.provisioning_requests p WHERE p.status = 'TENANT_CREATED' ORDER BY p.created_at, p.tenant_id;
$fn$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA prov_api FROM PUBLIC;

RESET ROLE;

GRANT USAGE ON SCHEMA prov_api TO eora_provisioner;
GRANT EXECUTE ON FUNCTION prov_api.begin_provisioning(text, text, text), prov_api.lock_request(text),
  prov_api.mark_tenant_created(text, uuid, uuid), prov_api.provision_lookup(text, text), prov_api.provisioned_tenants() TO eora_provisioner;

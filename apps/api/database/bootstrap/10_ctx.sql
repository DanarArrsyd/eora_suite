-- EORA signed tenant context (D-055, ADR-0001 Amendment 2, doc 25 §5.5). Run by bootstrap.sh as the bootstrap superuser,
-- connected to the target database, after 02_database.sql. Idempotent (CREATE IF NOT EXISTS / CREATE OR REPLACE).
-- Objects are owned by the NOLOGIN eora_ctx_verifier; no login role can read ctx.keys.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET log_statement = 'none';

SET ROLE eora_ctx_verifier;

CREATE TABLE IF NOT EXISTS ctx.keys (
  kid text PRIMARY KEY CHECK (kid ~ '^[a-z0-9]{1,16}$'),
  key bytea NOT NULL CHECK (length(key) = 32),
  not_after timestamptz NOT NULL DEFAULT 'infinity'
);
REVOKE ALL ON TABLE ctx.keys FROM PUBLIC;

-- Token: v1.<kid>.<tenant uuid | ->.<actor uuid>.<backend pid>.<transaction start µs>.<exp epoch s>.<hmac-sha256 hex>
-- "-" as tenant = tenant-less context (tenant picker before a tenant is chosen): tenant NULL, actor set.
-- Order: shape → active key → HMAC over the raw payload → parse only an authentic payload → binding checks.
-- Any failure returns NULLs, so policies fail closed. PARALLEL RESTRICTED is mandatory (D-056 P-01): inside a parallel
-- worker pg_backend_pid() is the worker's pid and a valid token would silently stop matching.
CREATE OR REPLACE FUNCTION ctx_api.context(OUT tenant uuid, OUT actor uuid)
LANGUAGE plpgsql STABLE SECURITY DEFINER PARALLEL RESTRICTED SET search_path = '' AS $fn$
DECLARE
  t text := pg_catalog.current_setting('app.ctx', true);
  n integer := pg_catalog.length(t);
  payload text;
  k bytea;
  f text[];
BEGIN
  IF n IS NULL OR n < 100 OR n > 300 OR pg_catalog.substr(t, n - 64, 1) <> '.' THEN
    RETURN;
  END IF;
  payload := pg_catalog.substr(t, 1, n - 65);
  SELECT ck.key INTO k FROM ctx.keys ck
   WHERE ck.kid = pg_catalog.split_part(payload, '.', 2) AND ck.not_after > pg_catalog.now();
  IF k IS NULL
     OR pg_catalog.encode(ext.hmac(pg_catalog.convert_to(payload, 'UTF8'), k, 'sha256'), 'hex') <> pg_catalog.substr(t, n - 63) THEN
    RETURN;
  END IF;
  -- authentic from here on: v1.<kid>.<tenant|->.<actor>.<pid>.<xact_us>.<exp>
  f := pg_catalog.string_to_array(payload, '.');
  IF pg_catalog.cardinality(f) <> 7 OR f[1] <> 'v1'
     OR f[5]::integer <> pg_catalog.pg_backend_pid()
     OR f[6]::numeric <> EXTRACT(epoch FROM pg_catalog.transaction_timestamp()) * 1000000
     OR f[7]::bigint <= EXTRACT(epoch FROM pg_catalog.now()) THEN
    RETURN;
  END IF;
  tenant := CASE WHEN f[3] = '-' THEN NULL ELSE f[3]::uuid END;
  actor := f[4]::uuid;
END
$fn$;
REVOKE ALL ON FUNCTION ctx_api.context() FROM PUBLIC;

RESET ROLE;

-- eora_app (and eora_provisioner by inheritance) may call the verifier; the table owner needs USAGE only, to create
-- policies that reference it (D-056 P-02). Nothing else is reachable.
GRANT USAGE ON SCHEMA ctx_api TO eora_app, eora_owner;
GRANT EXECUTE ON FUNCTION ctx_api.context() TO eora_app;

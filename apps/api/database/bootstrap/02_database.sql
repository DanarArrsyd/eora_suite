-- EORA database bootstrap, part 2: per-database ownership, schemas and base privileges (doc 25 §9 step 0).
-- Run by bootstrap.sh as the bootstrap superuser, connected to the target database. Idempotent.
-- Tables are created later by migrations as eora_owner; definer-owned objects by the versioned definer scripts.
\set ON_ERROR_STOP on
SET log_statement = 'none';

SELECT format('ALTER DATABASE %I OWNER TO eora_owner', current_database()) \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO eora_app, eora_auth_web, eora_auth_delivery, eora_provisioner, eora_relay',
              current_database()) \gexec

-- public: only eora_owner creates objects; runtime roles get per-table grants from migrations.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO eora_app, eora_provisioner, eora_auth_definer, eora_prov_definer, eora_outbox_definer;

-- Objects created by the bootstrap superuser before B1 (B0 infrastructure migration) move to eora_owner.
DO $do$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT c.oid::regclass AS rel, c.relkind
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
      AND c.relowner <> 'eora_owner'::regrole::oid
  LOOP
    EXECUTE format('ALTER %s %s OWNER TO eora_owner',
                   CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END,
                   r.rel);
  END LOOP;
  -- standalone sequences (sequences owned by a column follow their table above)
  FOR r IN
    SELECT c.oid::regclass AS rel
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind = 'S' AND c.relowner <> 'eora_owner'::regrole::oid
      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype IN ('a', 'i'))
  LOOP
    EXECUTE format('ALTER SEQUENCE %s OWNER TO eora_owner', r.rel);
  END LOOP;
END
$do$;

-- Extensions live in "ext", owned by the bootstrap superuser; only definer owners may use them.
CREATE SCHEMA IF NOT EXISTS ext;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA ext;
REVOKE ALL ON SCHEMA ext FROM PUBLIC;
GRANT USAGE ON SCHEMA ext TO eora_auth_definer, eora_ctx_verifier;

-- Definer-owned schemas (doc 25 §2.1, §2.5, §5.5, §6.4). No PUBLIC access; grants are added with their objects.
CREATE SCHEMA IF NOT EXISTS auth AUTHORIZATION eora_auth_definer;
CREATE SCHEMA IF NOT EXISTS auth_api AUTHORIZATION eora_auth_definer;
CREATE SCHEMA IF NOT EXISTS prov AUTHORIZATION eora_prov_definer;
CREATE SCHEMA IF NOT EXISTS prov_api AUTHORIZATION eora_prov_definer;
CREATE SCHEMA IF NOT EXISTS outbox_api AUTHORIZATION eora_outbox_definer;
CREATE SCHEMA IF NOT EXISTS ctx AUTHORIZATION eora_ctx_verifier;
CREATE SCHEMA IF NOT EXISTS ctx_api AUTHORIZATION eora_ctx_verifier;
ALTER SCHEMA auth OWNER TO eora_auth_definer;
ALTER SCHEMA auth_api OWNER TO eora_auth_definer;
ALTER SCHEMA prov OWNER TO eora_prov_definer;
ALTER SCHEMA prov_api OWNER TO eora_prov_definer;
ALTER SCHEMA outbox_api OWNER TO eora_outbox_definer;
ALTER SCHEMA ctx OWNER TO eora_ctx_verifier;
ALTER SCHEMA ctx_api OWNER TO eora_ctx_verifier;
REVOKE ALL ON SCHEMA auth, auth_api, prov, prov_api, outbox_api, ctx, ctx_api FROM PUBLIC;

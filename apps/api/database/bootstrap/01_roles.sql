-- EORA database bootstrap, part 1: cluster roles (doc 25 §5.1, D-B1-01, D-056). Run by bootstrap.sh as the bootstrap
-- superuser, connected to database "postgres". Idempotent: creates missing roles, re-asserts attributes, passwords,
-- memberships and per-role settings on every run. Passwords arrive as psql variables over stdin, never as files/args.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
-- Keep the password-bearing statements below out of every log and statistics view (D-056 P-06).
SET log_statement = 'none';
SET log_min_duration_statement = -1;
SET log_min_error_statement = 'panic';
SET pg_stat_statements.track_utility = off;

-- NOLOGIN owners of reviewed definer code; nobody is ever a member of them.
SELECT format('CREATE ROLE %I NOLOGIN', r)
FROM unnest(ARRAY['eora_auth_definer', 'eora_prov_definer', 'eora_outbox_definer', 'eora_lifecycle_definer', 'eora_ctx_verifier']) AS r
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec
-- LOGIN roles: migrations owner and the runtime roles (one per privilege domain, doc 25 §2.1).
SELECT format('CREATE ROLE %I LOGIN', r)
FROM unnest(ARRAY['eora_owner', 'eora_app', 'eora_auth_web', 'eora_auth_delivery', 'eora_provisioner', 'eora_relay']) AS r
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec

-- Attributes are re-asserted every run, so drift is repaired: no superuser, no BYPASSRLS, no role/database creation.
SELECT format('ALTER ROLE %I %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', r,
              CASE WHEN r LIKE '%\_definer' OR r = 'eora_ctx_verifier' THEN 'NOLOGIN' ELSE 'LOGIN' END)
FROM unnest(ARRAY['eora_auth_definer', 'eora_prov_definer', 'eora_outbox_definer', 'eora_lifecycle_definer', 'eora_ctx_verifier',
                  'eora_owner', 'eora_app', 'eora_auth_web', 'eora_auth_delivery', 'eora_provisioner', 'eora_relay']) AS r \gexec

ALTER ROLE eora_owner PASSWORD :'owner_pw';
ALTER ROLE eora_app PASSWORD :'app_pw';
ALTER ROLE eora_auth_web PASSWORD :'auth_web_pw';
ALTER ROLE eora_auth_delivery PASSWORD :'auth_delivery_pw';
ALTER ROLE eora_provisioner PASSWORD :'provisioner_pw';
ALTER ROLE eora_relay PASSWORD :'relay_pw';

-- The provisioner inherits eora_app's privileges but can never SET ROLE to it (doc 25 §2.1, §13).
GRANT eora_app TO eora_provisioner WITH INHERIT TRUE, SET FALSE;
-- The routing-approval definer (doc 26 §2.4a, M2 step 3) reads and writes through eora_app's tenant policies and grants
-- (same signed-context scope, never wider). The reverse never holds: no login role can become eora_lifecycle_definer,
-- which is what the version guard trigger checks with current_user.
GRANT eora_app TO eora_lifecycle_definer WITH INHERIT TRUE, SET FALSE;

-- Runtime hardening (D-056 P-03, D-B1-11): bound parameters (tokens, secrets) never reach the server log;
-- statement/lock/idle timeouts as role defaults (jobs and provisioning raise statement_timeout locally).
SELECT format('ALTER ROLE %I SET %s', r, s)
FROM unnest(ARRAY['eora_app', 'eora_auth_web', 'eora_auth_delivery', 'eora_provisioner', 'eora_relay']) AS r,
     unnest(ARRAY['log_parameter_max_length = 0', 'log_parameter_max_length_on_error = 0', 'statement_timeout = ''10s''',
                  'lock_timeout = ''3s''', 'idle_in_transaction_session_timeout = ''30s''']) AS s \gexec
ALTER ROLE eora_provisioner SET statement_timeout = '120s';

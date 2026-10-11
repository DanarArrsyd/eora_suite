#!/bin/sh
# EORA database bootstrap (doc 25 §5.1/§9 step 0, D-057). Idempotent; safe to re-run on an existing database.
# Runs as the bootstrap superuser (dev: Compose service "db-bootstrap"; CI: workflow step; prod: infrastructure job).
# Never drops anything. Passwords are read from the environment and passed to psql over stdin only.
#
# Required: PGHOST, PGUSER, PGPASSWORD (bootstrap superuser), EORA_BOOTSTRAP_DATABASES (space separated),
#           EORA_DB_{OWNER,APP,AUTH_WEB,AUTH_DELIVERY,PROVISIONER,RELAY}_PASSWORD,
#           EORA_CTX_KEYS — signed-context HMAC keys "kid:64-hex[,kid:64-hex]" (D-055); the API signs with one of them.
set -eu
dir=$(cd "$(dirname "$0")" && pwd)
: "${PGHOST:?}" "${PGUSER:?}" "${PGPASSWORD:?}" "${EORA_BOOTSTRAP_DATABASES:?}"

vars=""
for pair in owner_pw:EORA_DB_OWNER_PASSWORD app_pw:EORA_DB_APP_PASSWORD auth_web_pw:EORA_DB_AUTH_WEB_PASSWORD \
            auth_delivery_pw:EORA_DB_AUTH_DELIVERY_PASSWORD provisioner_pw:EORA_DB_PROVISIONER_PASSWORD \
            relay_pw:EORA_DB_RELAY_PASSWORD; do
  name=${pair%%:*}
  env_name=${pair#*:}
  eval "value=\${$env_name:-}"
  # Strong and quoting-safe: at least 24 characters from [A-Za-z0-9_-] (generate with: openssl rand -hex 24).
  if ! printf '%s' "$value" | grep -Eq '^[A-Za-z0-9_-]{24,}$'; then
    echo "bootstrap: $env_name must be set to at least 24 characters of [A-Za-z0-9_-]" >&2
    exit 1
  fi
  vars="$vars\\set $name '$value'
"
done

# Signed-context keys: validated strictly, then upserted with statement logging off (the values never reach logs,
# pg_stat_statements or files; D-056 P-06). Keys are only added or replaced, never deleted (rotation = retire by not_after).
: "${EORA_CTX_KEYS:?}"
key_sql="SET log_statement = 'none';
SET log_min_error_statement = 'panic';
SET pg_stat_statements.track_utility = off;
SET pg_stat_statements.track = 'none';
"
old_ifs=$IFS
IFS=','
for entry in $EORA_CTX_KEYS; do
  if ! printf '%s' "$entry" | grep -Eq '^[a-z0-9]{1,16}:[0-9a-f]{64}$'; then
    echo "bootstrap: EORA_CTX_KEYS entries must be kid:64-lowercase-hex (generate with: openssl rand -hex 32)" >&2
    exit 1
  fi
  key_sql="$key_sql INSERT INTO ctx.keys (kid, key) VALUES ('${entry%%:*}', decode('${entry#*:}', 'hex'))
  ON CONFLICT (kid) DO UPDATE SET key = EXCLUDED.key;
"
done
IFS=$old_ifs
# Autovacuum never analyzes a table of a few rows: without explicit statistics the verifier's key lookup gets a poor
# plan and costs ~18 µs instead of ~10 µs per evaluation (measured 2026-10-11, D-055 budget 15 µs).
key_sql="$key_sql ANALYZE ctx.keys;
"

psql_super() { psql -X -q -v ON_ERROR_STOP=1 "$@"; }

printf '%s' "$vars" | cat - "$dir/01_roles.sql" | psql_super -d postgres
for db in $EORA_BOOTSTRAP_DATABASES; do
  if ! printf '%s' "$db" | grep -Eq '^[a-z][a-z0-9_]{0,62}$'; then
    echo "bootstrap: invalid database name '$db'" >&2
    exit 1
  fi
  exists=$(psql_super -d postgres -tA -c "SELECT 1 FROM pg_database WHERE datname = '$db'")
  if [ "$exists" != "1" ]; then
    psql_super -d postgres -c "CREATE DATABASE \"$db\" OWNER eora_owner"
  fi
  psql_super -d "$db" -f "$dir/02_database.sql"
  psql_super -d "$db" -f "$dir/10_ctx.sql"
  printf '%s' "$key_sql" | psql_super -d "$db"
  echo "bootstrap: database $db ready"
done

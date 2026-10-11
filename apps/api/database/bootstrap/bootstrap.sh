#!/bin/sh
# EORA database bootstrap (doc 25 §5.1/§9 step 0, D-057). Idempotent; safe to re-run on an existing database.
# Runs as the bootstrap superuser (dev: Compose service "db-bootstrap"; CI: workflow step; prod: infrastructure job).
# Never drops anything. Passwords are read from the environment and passed to psql over stdin only.
#
# Required: PGHOST, PGUSER, PGPASSWORD (bootstrap superuser), EORA_BOOTSTRAP_DATABASES (space separated),
#           EORA_DB_{OWNER,APP,AUTH_WEB,AUTH_DELIVERY,PROVISIONER,RELAY}_PASSWORD
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
  echo "bootstrap: database $db ready"
done

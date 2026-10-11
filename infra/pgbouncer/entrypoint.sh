#!/bin/sh
# Writes the auth file from the environment at start (never baked into the image); only the pooled runtime roles.
set -eu
umask 077
: "${EORA_DB_APP_PASSWORD:?}"
printf '"%s" "%s"\n' eora_app "$EORA_DB_APP_PASSWORD" > /tmp/userlist.txt
exec pgbouncer /etc/pgbouncer/pgbouncer.ini

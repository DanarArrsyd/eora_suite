#!/bin/sh
# Adds missing LOCAL development secrets to ./.env (gitignored) with random values. Never changes or prints an
# existing value, never touches anything but missing keys. Usage (repository root): sh scripts/ensure-dev-secrets.sh
set -eu
cd "$(dirname "$0")/.."
[ -f .env ] || { echo "no .env yet: cp .env.example .env first" >&2; exit 1; }
umask 077
added=""
for key in EORA_DB_OWNER_PASSWORD EORA_DB_APP_PASSWORD EORA_DB_AUTH_WEB_PASSWORD EORA_DB_AUTH_DELIVERY_PASSWORD \
           EORA_DB_PROVISIONER_PASSWORD EORA_DB_RELAY_PASSWORD EORA_CTX_KEY_KID1 EORA_PEPPER_KEY; do
  # signed-context HMAC key: 32 bytes as 64 hex characters (D-055); role passwords: 24 random bytes as hex
  bytes=24
  case "$key" in EORA_CTX_KEY_KID1|EORA_PEPPER_KEY) bytes=32 ;; esac
  if ! grep -Eq "^${key}=.+" .env; then
    if grep -Eq "^${key}=$" .env; then
      # empty placeholder line: replace it in place (portable sed -i)
      tmp=$(mktemp)
      sed "s/^${key}=\$/${key}=$(openssl rand -hex $bytes)/" .env > "$tmp" && cat "$tmp" > .env && rm -f "$tmp"
    else
      printf '%s=%s\n' "$key" "$(openssl rand -hex $bytes)" >> .env
    fi
    added="$added $key"
  fi
done
echo "ensure-dev-secrets: ${added:- nothing missing}"

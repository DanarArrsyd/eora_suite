# EORA Suite API (Laravel) — B0 foundation

Laravel 13 modular monolith (ADR-0007, doc 09). B0 contains **no business schema or modules** — only the foundation
(API v1 routing, health, problem+json errors, correlation ids, JSON logs, PostgreSQL/Redis configuration, OpenAPI export,
tests, CI). Tenancy, RBAC, Sanctum, RLS and the inventory ledger come in later slices (doc 11) after owner approval.

## Versions (pinned in composer.lock)
PHP ≥ 8.4.12 (CI/Docker 8.4, also verified on 8.5) · Laravel 13.33.0 · Pest 5.2.1 (PHPUnit 13.3.4) · Larastan 3.12.2 · Pint 1.32.1 ·
Predis 3.6.1 · Scramble 0.13.45 · PostgreSQL 18 · Redis 8.6 (Docker images `postgres:18-alpine`, `redis:8.6-alpine`).

## Run locally
Option A — everything in Docker (recommended):
```bash
cp .env.example .env
```
Fill `EORA_DB_PASSWORD` (bootstrap superuser) and `EORA_APP_KEY` in the repository-root `.env`, add the role passwords, then start:
```bash
sh scripts/ensure-dev-secrets.sh
```
```bash
docker compose up -d --build --wait
```
```bash
curl -fsS http://127.0.0.1:8000/api/v1/health/ready
```
Migrations run as the migrations owner in the one-shot `db-migrate` service (it also runs automatically on `up`); the
long-running API container holds only the runtime role's credentials (doc 25 §10):
```bash
docker compose run --rm db-migrate
```
Backend tests (they need the owner for the group A schema guard, so they run in the same one-shot container):
```bash
docker compose run --rm --entrypoint vendor/bin/pest db-migrate
```
Notes for Docker Desktop on macOS:
- The `docker` CLI lives in `~/.docker/bin` (add it to `PATH` if the shell does not find it).
- The PostgreSQL init SQL (creates `eora_test`) ships inline in `compose.yaml` (`configs.content`), so no host folder has to be
  shared with Docker Desktop. It runs only when the `eora_pgdata` volume is created; an existing volume keeps its data.
- To exercise a fresh initialisation without touching development data, use a separate project and ports, e.g.
  `EORA_DB_HOST_PORT=55432 EORA_REDIS_HOST_PORT=56380 EORA_API_HOST_PORT=58000 docker compose -p eora_freshcheck up -d --wait`.
- `docker compose down` keeps volumes; `down -v` deletes the database volume — never run it on the development project
  without meaning to.

Option B — PHP on the host, services in Docker:
```bash
docker compose up -d --wait postgres redis
```
```bash
cd apps/api && cp .env.example .env && composer install && php artisan key:generate
```
Set `DB_PASSWORD` (= `EORA_DB_APP_PASSWORD`) and `DB_OWNER_PASSWORD` (= `EORA_DB_OWNER_PASSWORD`) in `apps/api/.env`, then:
```bash
php artisan migrate --database=pgsql_owner && php artisan serve
```

## Quality commands (apps/api)
```bash
composer check
```
Runs `pint --test`, `phpstan analyse` (level 8) and `pest` (unit, feature, architecture, integration). Without PostgreSQL/Redis
running, use `composer test:unit` (excludes the `integration` group).

Tests always use the `eora_test` database: `phpunit.xml` forces the test configuration over the process environment, and
`tests/Support/TestDatabaseGuard.php` aborts the run before any query if the target is not a `*_test` database (or `APP_ENV` is
not `testing`, or `DB_URL` is set). Running the suite in the `db-migrate` container (see above) is therefore safe.

## Database roles (M1 / B1 step 1, doc 25 §5.1, D-057)
| Role | Login | Used by |
|---|---|---|
| bootstrap superuser (`EORA_DB_USERNAME`) | yes | `db-bootstrap` service only (roles, schemas, extensions) |
| `eora_owner` | yes | migrations and group A schema tests — credentials only in the one-shot `db-migrate` service, never in the API container |
| `eora_app` | yes | every request and job (default connection); no superuser, no BYPASSRLS, owns nothing |
| `eora_auth_web`, `eora_auth_delivery`, `eora_provisioner`, `eora_relay` | yes | auth, secret delivery, provisioning, outbox relay (later M1 steps) |
| `eora_auth_definer`, `eora_prov_definer`, `eora_outbox_definer`, `eora_ctx_verifier` | **no** | owners of reviewed `SECURITY DEFINER` code |

`database/bootstrap/bootstrap.sh` is idempotent: it creates missing roles, re-asserts their attributes, passwords and
hardening (`log_parameter_max_length = 0`, timeouts), hands objects created by the superuser to `eora_owner`, and creates
the definer-owned schemas. It never drops anything. Docker Compose runs it before the API starts.
The image links `pdo_pgsql` against libpq ≥ 17 from apt.postgresql.org (native prepares through PgBouncer, D-056 P-05).
Tests refuse non-`*_test` databases and non-local hosts (`EORA_TEST_DB_HOST_ALLOW` overrides the host list).

## Tenant context and isolation (M1 / B1 step 2, D-055, ADR-0001 Amendment 2)
- Tenant tables (`tenants`, `companies`, `plants`) use **forced RLS** bound to the **signed tenant context**:
  `tenant_id = (SELECT c.tenant FROM ctx_api.context() c)`. Composite tenant foreign keys; the runtime role has no DELETE.
- `App\Platform\Tenancy\TenantTransaction::run(TenantContext, fn)` is the only way to do tenant work. It opens the
  outermost transaction with `BEGIN` + the transaction binding (backend pid, transaction start) in one round trip
  (`EoraPostgresConnection`), signs `v1.<kid>.<tenant|->.<actor>.<pid>.<xact_us>.<exp>.<hmac>` (`ContextSigner`) and sets
  it as a bound, transaction-local `app.ctx` directly on PDO, so the token never reaches Laravel query events or logs.
- The verifier `ctx_api.context()` (definer, `PARALLEL RESTRICTED`, owned by the NOLOGIN `eora_ctx_verifier`) checks
  the HMAC with the key in `ctx.keys` (loaded by `db-bootstrap` from `EORA_CTX_KEYS`, then `ANALYZE`d) and the binding;
  anything else returns NULL and the policies return no rows. A tenant-less token (`-`) serves the tenant picker.
- Runtime traffic goes through **PgBouncer** (`infra/pgbouncer`, transaction pooling, `max_prepared_statements`);
  owner and provisioner connect directly. `eora_test` uses a 2-connection pool so tests exercise connection reuse.
- Keys: `EORA_CTX_KEY_KID1` in the root `.env` (`scripts/ensure-dev-secrets.sh` creates it); the API reads
  `EORA_CTX_SIGNING_KID` / `EORA_CTX_SIGNING_KEY`.
- Tests: `tests/Feature/Tenancy` — T-ISO-02/02b/03, the T-CTX suite ported from the D-B1-24 PoC, and the D-055
  performance gate (≤ 15 µs per verifier evaluation, ≤ 0.3 ms p95 per transaction versus Option A).

## Business calendar and security events (M1 / B1 step 3)
- `business_calendars` (tenant default `plant_id` NULL → plant override), `calendar_exceptions`, `shift_calendars`,
  `shifts`: versioned by `effective_from`, immutable for the runtime role (a change is a new version), forced RLS.
- `App\Platform\Calendar\BusinessCalendar::resolve($db, $plantId, $instant)` → calendar date, production date
  (cut-off; a shift belongs wholly to the production date of its start), shift, ISO week (ADR-0012, doc 14 #62).
  No configured calendar → exception; nothing is guessed.
- `security_events` (doc 25 §8): append-only (no UPDATE/DELETE grants, trigger blocks TRUNCATE), the runtime role
  inserts only `source = 'app'` rows for its own tenant and actor; `auth.*` actions are reserved for auth sources.
  `SecurityEventRecorder` buffers events during the request/job and `flush()`es them after the outer transaction
  (logged first; a failed write is queued as `RetrySecurityEventFlush`). Client IP / user agent are stored only as
  keyed hashes (`SecurityHasher`, `EORA_PEPPER_KEY`, D-B1-22).

## Identity and invitations — database layer (M1 / B1 step 4a, doc 25 §2)
- `auth` schema (bootstrap `database/bootstrap/20_auth.sql`, owned by NOLOGIN `eora_auth_definer`): identities,
  login failures, rate buckets, invitations, delivery queue. No login role can read it; the only surface is
  `auth_api`, one grantee per function (schema guard allowlist):
  `eora_auth_web` → `login_lookup`, `record_login_failure`, `invite_identity`, `record_security_event`,
  `consume_invitation`; `eora_auth_delivery` → `claim_invitation_dispatch`, `claim_delivery`,
  `issue_invitation_secret`, `mark_delivery_result`; `eora_provisioner` → `provision_identity`.
- Public tables (migration): `users` (profile, no secrets; runtime may edit only its own name/locale),
  `memberships` (tenant-scoped + tenant-less picker read), `invitation_dispatch_requests` (durable hand-off).
- Rules: random UUIDv4 user ids; first password only via an accepted invitation; secrets 256-bit, stored as SHA-256,
  usable only after the delivery is acknowledged (`SENT`), single use, rotated on every re-issue, 72 h TTL; lease +
  bounded retries with backoff; lockout after 10 failures in 15 min (lookup then returns no hash).
- Connections: `pgsql_auth` (`eora_auth_web`), `pgsql_delivery` (`eora_auth_delivery`).

## Authentication over HTTP (M1 / B1 step 4b, ADR-0006, doc 25 §4)
- Sanctum stateful SPA: `GET /sanctum/csrf-cookie`, then every non-GET sends `X-XSRF-TOKEN` (419
  `CSRF_TOKEN_MISMATCH` otherwise). Session in Redis, cookie `__Host-eora_session` (Secure, HttpOnly, SameSite=Lax),
  idle 30 min, absolute 12 h (`AuthSession::requireUser`), regenerated on login and tenant/plant switch.
- `POST /api/v1/auth/login` (argon2id verified in PHP, constant work, one uniform 401, 5/min per account + 20/min per IP),
  `POST /auth/logout`, `GET /auth/me`, `POST /auth/tenant` (only tenants with an ACTIVE membership),
  `POST /auth/plant`, `POST /auth/invitations/accept` (token + password → membership ACTIVE).
- Business routes use the `tenant` middleware: one `TenantTransaction` per request with the session's signed context,
  credential version + ACTIVE membership re-checked under RLS (401 `SESSION_EXPIRED`), GET read-only, any 4xx/5xx
  rolls back. 403/404 of signed-in users become `security_events` (flushed after the transaction).
- Invitation emails: `php artisan eora:deliver-invitations` (Compose service `delivery-worker`, role
  `eora_auth_delivery` only). Dev mail lands in Mailpit: http://127.0.0.1:8025. The link carries the secret in the URL
  fragment (`/invitations/accept#token=…`).

## Password reset, password policy, orphan cleanup (M1 / B1 step 4c, doc 25 §2.3–§2.4)
- `POST /api/v1/auth/password/forgot` always answers 202 after ≥ 400 ms, whether the email exists or not (3/min per
  email, 10/min per IP at HTTP; in the database 3 requests per identity per hour and 60 reset deliveries per minute
  overall). Unknown, disabled and password-less identities get nothing. A new request supersedes the previous link.
- The delivery worker issues the reset secret (256-bit, SHA-256 stored, usable only after the send is acknowledged,
  30 min TTL, single use) and mails `/password/reset#token=…`.
- `POST /api/v1/auth/password/reset` (token + password + confirmation): 204 or one uniform 422. Success sets the
  password, clears a lockout, bumps `credential_version` (every session of the user ends) and writes
  `auth.password_reset_consumed` per ACTIVE membership.
- Password rule (`PasswordPolicy`, invitation acceptance and reset): ≥ 12 characters and not on the offline list
  `resources/security/breached-passwords.txt` (case-insensitive; the rule fails closed if the list is missing). The
  shipped list is a small seed: replace it with a vetted breached-password corpus before pilot.
- The worker removes orphan identities every 10 minutes (`auth_api.cleanup_orphan_identities`): no password, no
  membership, no open delivery, no unexpired invitation, older than 7 days.

## Roles, permissions and scoped assignments (M1 / B1 step 5, doc 25 §3, §13; doc 18)
- Global, read-only catalogue (migration `000500`): `permissions` (code `<module>.<resource>.<action>`, actions view,
  create, submit, post, approve, request_correction, export; `sensitive` = D-B1-25 set), `role_templates` (doc 18 §1 +
  `TENANT_ADMIN` of D-B1-16; no platform template), `role_template_permissions` (doc 18 §2 + §5 letters).
- Tenant tables (forced RLS): `roles` + `role_permissions` (template copies, `RoleTemplates::instantiate`, idempotent;
  not editable in M1), `role_assignments` (scope `TENANT` / `COMPANY` / `PLANT`, validity window, grant/revoke reason,
  revocation keeps the row; no DELETE).
- `Authorizer` evaluates the signed actor's unrevoked, currently valid assignments held through an ACTIVE membership:
  `requireAnywhere` → 403 `FORBIDDEN`, `requireCovered` → 403 `SCOPE_FORBIDDEN`, `authorize(perm, Scope)` = both.
  No cache: a change applies to the next request.
- Escalation rules: grantor scope ⊇ target scope; nobody grants or revokes their own assignments (403
  `SOD_VIOLATION`, also enforced by the `eora_guard_role_assignment` trigger); grantor/revoker stamped from the signed
  actor; only revocation columns are updatable; a write locks the grantor's assignments `FOR SHARE`, so a concurrent
  revocation of them answers 503 `RETRYABLE_CONFLICT` (`Retry-After: 1`).
- Endpoints (`tenant` middleware): `GET /api/v1/core/roles`, `GET /api/v1/core/members` (`core.users_roles.view`),
  `POST /api/v1/core/role-assignments`, `POST /api/v1/core/role-assignments/{id}/actions/revoke`
  (`core.users_roles.post`). `GET /auth/me` adds `permissions` (navigation hint only).

## Member administration (M1 / B1 step 5b, doc 25 §2.4, §13)
- `POST /api/v1/core/members/invitations` `{email}` (`core.users_roles.create`): resolves the identity on the auth
  connection (`auth_api.invite_identity`), writes the INVITED membership and an INITIAL dispatch request in the tenant
  transaction → 201 member; the delivery worker sends the mail. Existing member → 409. Inviting grants no role.
- `POST /core/members/{id}/actions/resend-invitation` (`create`): INVITED only → 202; new secret, old link invalid;
  database cap 5 per membership per 24 h. Optional per-administrator cap on invitations + resends per hour:
  `EORA_MEMBER_INVITE_PER_HOUR` (off unless set; no approved value yet).
- `POST /core/members/{id}/actions/suspend` (INVITED/ACTIVE → SUSPENDED) and `/reactivate` (→ ACTIVE if it had been
  activated, else INVITED), permission `core.users_roles.post`: never one's own membership (403 `SOD_VIOLATION`), and
  the administrator's scope must cover every unrevoked assignment of the member (403 `SCOPE_FORBIDDEN`, D-068).
  Suspension ends access on the member's next request; assignments are kept.

## Tenant provisioning (M1 / B1 step 6, doc 25 §2.5, D-B1-13, D-B1-16)
```bash
php artisan eora:tenant:provision demo-stamping "Demo Stamping" admin@example.test \
  --company-code=DS --company-name="PT Demo Stamping" --plant-code=P1 --plant-name="Plant 1" \
  --day-starts=07:00 [--timezone=Asia/Jakarta] [--deliver=email|console]
```
- Runs as `eora_provisioner` only: registry `prov.provisioning_requests` through `prov_api` (bootstrap `30_prov.sql`,
  owner NOLOGIN `eora_prov_definer`), identity via `auth_api.provision_identity`, then one tenant transaction in the
  new tenant's signed context (system actor `00000000-0000-0000-0000-000000000000`): tenant, company, plant,
  tenant-default business calendar, roles from templates, INVITED membership + dispatch request, `TENANT_ADMIN` at
  tenant scope, tenant ACTIVE, registry `TENANT_CREATED`. All or nothing.
- Idempotent: the same code + admin e-mail resumes; after success it only queues a resend for the first
  administrator. Same code with another e-mail → `TENANT_CODE_TAKEN`.
- `--deliver=email` (default): the delivery worker mails the invitation. `--deliver=console`: the link is printed in
  the operator's terminal (CONSOLE channel, never mailed, never logged; `invitation.delivered_console` event).
- The production-day start has no default (tenant input, ADR-0012); the time zone defaults to Asia/Jakarta (D-044).
- The long-running `api` container has no provisioner or delivery credentials (least privilege). Locally, run the command
  in the one-shot runner that has them, e.g.
  `docker compose run --rm --entrypoint php -e EORA_FRONTEND_URL=http://localhost:3100 db-migrate artisan eora:tenant:provision …`

## Business audit and inbox (M1 / B1 step 7a, doc 25 §8, D-069)
- `AuditRecorder::record($db, action, entityType, entityId, before, after, detail)` inside the command transaction →
  `audit_events` (append-only; actor and time stamped by the database; before/after as SHA-256 of canonical JSON;
  detail = ids and codes, never e-mails or secrets). Recorded: `role_assignment.granted|revoked`,
  `membership.invited|invitation_resent|activated|suspended|reactivated`, `tenant.provisioned`.
- `inbox_notifications`: a user sees and marks only its own rows. Granting a role with sensitive permissions notifies
  every other ACTIVE administrator (`access.sensitive_role_granted`, D-069). `GET /api/v1/inbox`, `POST /inbox/read`.

## Transactional outbox and relay (M1 / B1 step 7b, doc 25 §6.4)
- `OutboxPublisher::publish($db, type, aggregateType, aggregateId, payload)` inside the command transaction →
  `outbox_events` (`source = 'app'`; `auth.*` types reserved for auth sources). `membership.invited` is published on
  invitations and provisioning (business event only; delivery uses dispatch requests).
- `consume_password_reset` writes `auth.password_reset_consumed` (source `auth_web`) per ACTIVE tenant.
- Relay: `php artisan eora:outbox:relay` (Compose `outbox-relay`). Claims through `outbox_api.claim_events` as
  `eora_relay` (ids, tenant ids, claim tokens only; 2-minute lease; 10 attempts then DEAD; backoff 1/5/15/60 min),
  then handles each event as `eora_app` in the event's tenant; `processed_events` = at most once per consumer.
- Consumer `PrivilegedResetNotifier` (D-B1-25): a reset by a user holding sensitive permissions notifies the tenant
  administrators and the user (ACTIVE only) in the inbox; untrusted events → `outbox.untrusted_event`.

## Error contract in OpenAPI and rate limits (M1 / B1 step 7c)
- `App\Platform\Http\OpenApi\ProblemResponses` (Scramble operation + document transformer) documents every v1 error
  as `application/problem+json` with the endpoint's codes from doc 24 §5; `ProblemResponses::ERRORS` must list every
  `v1.*` route (contract test).
- Login: 5/min per account, 20/min per IP; 60/min per IP for factory NAT addresses in `EORA_LOGIN_NAT_ALLOWLIST`
  (comma-separated IPs/CIDRs, D-B1-06). The client IP is taken from `X-Forwarded-For` only through the hops listed in
  `EORA_TRUSTED_PROXIES` (exact IPs/CIDRs; empty = trust none, never `*`). Review of all limits: doc 25 §16.

## Endpoints (B0)
| Method | Path | Purpose |
|---|---|---|
| GET | `/up` | Laravel framework liveness |
| GET | `/api/v1/health` | liveness (no dependency calls) |
| GET | `/api/v1/health/ready` | readiness: PostgreSQL + Redis; `503` + `degraded` when a dependency fails; versions hidden in production |
| GET | `/docs/api` | Scramble UI (local environment only) |
All `/api/*` errors are RFC 9457 `application/problem+json` with `code`, `correlationId`, `reference` (doc 24 §5). Every response
carries `X-Correlation-Id` (accepted from a well-formed `X-Request-Id`).

## OpenAPI and typed client
```bash
composer openapi:export
```
Writes `packages/api-client/openapi.json`; then from the repository root:
```bash
npx pnpm@12.10.1 --filter @eora/api-client generate
```
CI fails when the committed spec or `schema.d.ts` differs from the code.

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

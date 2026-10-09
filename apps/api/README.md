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
Fill `EORA_DB_PASSWORD` and `EORA_APP_KEY` in the repository-root `.env`, then:
```bash
docker compose up -d --build --wait
```
```bash
curl -fsS http://127.0.0.1:8000/api/v1/health/ready
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
Set `DB_PASSWORD` in `apps/api/.env` to the same value as `EORA_DB_PASSWORD`, then:
```bash
php artisan migrate && php artisan serve
```

## Quality commands (apps/api)
```bash
composer check
```
Runs `pint --test`, `phpstan analyse` (level 8) and `pest` (unit, feature, architecture, integration). Without PostgreSQL/Redis
running, use `composer test:unit` (excludes the `integration` group).

Tests always use the `eora_test` database: `phpunit.xml` forces the test configuration over the process environment, and
`tests/Support/TestDatabaseGuard.php` aborts the run before any query if the target is not a `*_test` database (or `APP_ENV` is
not `testing`, or `DB_URL` is set). Running the suite inside the api container is therefore safe:
```bash
docker compose exec api vendor/bin/pest
```

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

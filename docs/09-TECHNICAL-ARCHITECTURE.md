# 09 — Technical Architecture, Performance & Deployment

## Baseline
Modular monolith API (Laravel), Next.js TypeScript web/PWA, PostgreSQL, Redis queues/Horizon, Reverb realtime, S3-compatible storage; Docker for local and deploy; pnpm/Turborepo for JS workspaces; OpenAPI generated typed client; modular bounded contexts. Stable supported versions, pin lockfiles and record ADRs.

> **Update 2026-10-08:** Next.js + Laravel + Sanctum cookie auth decided (D-016). Same-origin topology, cookie/CSRF/CORS strategy: ADR-0006. Runtime (queues, outbox, realtime, storage, version pinning): ADR-0007 (Proposed). Workload model: `20-PERFORMANCE-WORKLOAD-MODEL.md`.

## Request flow
Client → CDN/WAF/load balancer → Next.js app / Laravel API → domain application service → scoped authorization + validation → DB transaction (ledger/projections + outbox) → fast response → workers (notification, export, aggregates). Do not block stock posting on PDF or push. Critical writes use idempotency key, row locks/optimistic version, unique constraints and transaction retry policy.

## Performance
Server-side table filtering/sorting/pagination; targeted query indexes and EXPLAIN plans; TanStack Query caching/invalidation; dashboard aggregates with freshness labels; async large import/export; separate workers; object storage; connection pooling and stateless app replicas. Cache invalidation must respect tenant, plant and permission scope.

## Initial measurable targets (proposed, not guaranteed)
Representative simple read API p95 <300 ms; normal stock posting p95 <800 ms under agreed test environment and load; 100 concurrent virtual users initial test then scale tests at 500/1000 if justified. Zero duplicate postings/negative stock where prohibited and no tenant leaks in concurrency tests. Define realistic transaction mix and hardware before accepting SLOs.

## Operations
Structured logs with correlation IDs, OpenTelemetry traces, Sentry, metrics/alerts, backup/restore drills, RPO/RTO targets to decide, secrets manager, staging/prod isolation, migration strategy, blue/green or rolling deploy, rollback/runbook. Load-test material issue, QC release, stock count and import simultaneously.

## Repo suggestion
`apps/web`, `apps/api`, `packages/ui`, `packages/features`, `packages/design-tokens`, `packages/api-client`, `packages/config`, `docs`, `infra`, `.github/workflows`. Laravel dependency management via Composer, not pnpm.

## Backend architecture blueprint (Phase 3.5, PROPOSED — not implemented)
```
apps/api (Laravel modular monolith, ADR-0007)
  app/Modules/<Module>/
    Contracts/        DTOs, command/query interfaces, domain events (the only cross-module surface)
    Domain/           entities/value objects, state machines (doc 16), policies, rule objects (BR-*)
    Application/      command handlers (idempotent), query definitions (ADR-0008), event consumers
    Infrastructure/   Eloquent repositories (tenant-scoped), read-model projectors, external adapters
    Http/             controllers (thin), FormRequests, API resources (OpenAPI via ADR-0013)
  app/Platform/       TenantContext + TenantTransaction middleware, idempotency, outbox relay, audit, calendar service
```
Modules: Core, Demand, Planning, Materials, Production, Quality, **Inventory (only ledger writer)**, Warehouse, Delivery, Maintenance, Traceability (reconciliation), Analytics (read models). Cross-module calls only through `Contracts`; enforced by architecture tests in CI.

**Command pipeline:** HTTP → auth (Sanctum session, CSRF) → rate limit → `TenantTransaction` (BEGIN; `set_config('app.tenant_id', …, true)`; `SET LOCAL statement_timeout/lock_timeout`) → FormRequest validation → policy (permission + scope + SoD) → idempotency check (`idempotency_records`) → command handler → domain rules → `InventoryPostingService` when stock moves (doc 04 §posting) → audit + outbox rows → COMMIT → response. External I/O (files, push, PDF) only in workers after commit.

**Query pipeline:** HTTP → auth → `TenantTransaction` (read) → QueryDefinition (scope predicates, allowed filters/sorts, keyset/offset, column projection) → resource → `meta` (asOf, freshness, scopeHash).

**Read models:** projectors consume outbox events (idempotent `processed_events`), write rebuildable tables with the ledger high-water mark; rebuild commands per model; nightly verification vs ledger (BR-INV-11, doc 19 §4).

**Background jobs (Horizon queues):** `outbox-relay` (SKIP LOCKED), `projections`, `notifications`, `exports`, `imports`, `maintenance` (nightly reconciliation). Every job carries tenant + actor; job middleware opens the tenant transaction (ADR-0001 §7).

**B0 status (2026-10-09):** `apps/api` implements `app/Platform` (correlation id middleware, problem+json renderer, health checker/controller) and the empty `app/Modules` convention with architecture tests; `routes/api.php` exposes `/api/v1/health` and `/api/v1/health/ready`; JSON log channel on stderr with Laravel Context (`correlation_id`). Setup: `apps/api/README.md`.

## Performance & scalability plan (Phase 3.5)
| Area | Strategy |
|---|---|
| PostgreSQL | tenant-leading composite indexes (doc 17 §6.5), EXPLAIN for every QueryDefinition before release, `pg_stat_statements` monitoring, autovacuum tuning for ledger/outbox, monthly partitioning of `inventory_movements`/`audit_events` when volume justifies (doc 20) |
| Connection pooling | PgBouncer **transaction mode**; tenant context is transaction-local (ADR-0001) so pooling is safe; Laravel PDO with emulated prepares (or pooler prepared-statement support) to avoid server-side prepared statement clashes; pool sized from cores, not users |
| Hot-row contention | posting locks balance rows in deterministic order, short transactions, bounded retries; W-07 hot-lot test; allocation checks under the same lock |
| Caching (Redis) | only read-model query results and schema/options; key = `t:{tenant}:{resource}:{scopeHash}:{querySignature}`; TTL ≤ freshness threshold; invalidated by outbox events per resource; never cache ledger balances for posting decisions |
| Read models | Control Tower overview and aggregates from projectors (lag target p95 < 2 min); detail lists from live QueryDefinitions with keyset pagination |
| Import/export workers | separate queue + worker pool; chunked (500 rows) idempotent commits; exports stream to object storage |
| Horizontal scaling | stateless API and Next.js replicas; workers scale per queue; Reverb scaled separately; DB vertical first, read replica for read models only when measured |
| Observability | OpenTelemetry traces (Next.js → Laravel → DB/queue), JSON logs with correlation/tenant/user ids, metrics: posting latency, lock waits, deadlock retries, outbox lag, projection lag, queue depth, cache hit rate, 429s |
Sizing (D-019) remains a **test assumption**, not a capacity guarantee (doc 20).

## Agent skill integration
Engineering and architecture reviews must discover/read the **Caveman** skill and relevant **Frontend** skill instructions at task start. Record applicability and constraints in the review; never invent a skill call or imply availability without verification. Skills augment but never supersede security, transactional correctness or approval requirements.

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

## Agent skill integration
Engineering and architecture reviews must discover/read the **Caveman** skill and relevant **Frontend** skill instructions at task start. Record applicability and constraints in the review; never invent a skill call or imply availability without verification. Skills augment but never supersede security, transactional correctness or approval requirements.

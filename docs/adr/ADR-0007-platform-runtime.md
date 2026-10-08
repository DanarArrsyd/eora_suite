# ADR-0007 — Platform runtime: modules, queues, outbox, realtime, storage, versions

- **Status:** Proposed (P-001 remainder, P-005) · **Date:** 2026-10-08 · Becomes Accepted after Foundation-stage compatibility check.

## Context
D-016 fixes Next.js + Laravel + Sanctum and D-009 fixes PostgreSQL. Remaining runtime choices (Redis/Horizon, Reverb, S3-compatible storage, Docker, versions) were proposed in the baseline and need a compatibility/version check before acceptance.

## Decision (proposed)
1. **Laravel modular monolith**: `apps/api/app/Modules/{Core, Demand, Planning, Materials, Production, Quality, Inventory, Warehouse, Delivery, Maintenance, Traceability, Analytics}`. Each module exposes `Contracts/` (interfaces, DTOs) and domain events; other modules may depend only on `Contracts`. Enforced in CI by an architecture test (e.g. Pest arch tests or deptrac) — tool chosen at Foundation.
2. **Only Inventory writes ledger/balances**, through `InventoryPostingService` (ADR-0002). Other modules submit posting commands via its contract.
3. **Transactional outbox**: `outbox_events` row inserted in the same transaction as the domain change. A relay (scheduled worker using `FOR UPDATE SKIP LOCKED`) publishes to Redis queues. Consumers are idempotent (`processed_events` with unique `(consumer, event_id)`). At-least-once; no exactly-once claims. Dead-letter after N retries with alert.
4. **Redis** for sessions, cache, queues (Horizon). Redis is not a source of truth; losing it may delay notifications but never loses domain events (outbox persists).
5. **Reverb** for realtime with polling fallback for bell counts.
6. **S3-compatible storage** for documents, imports, exports; tenant-prefixed keys; signed short-lived URLs; malware scanning for uploads where appropriate.
7. **Docker** images for api, web, worker, reverb; Docker Compose for local; CI on GitHub Actions.
8. **Versions:** select current supported stable releases of PHP, Laravel, Node.js LTS, Next.js, React, PostgreSQL and Redis at Foundation start; pin exact versions and lockfiles; record them in this ADR with support end dates. Versions are deliberately **not** fixed in this document to avoid stale assumptions.
9. Observability: structured JSON logs with `correlation_id`, `tenant_id`, `user_id`; OpenTelemetry traces across Next.js → Laravel → DB/queue; error tracking; metrics for outbox lag, queue depth, posting latency, lock waits.

## Consequences
+ Single deployable backend, clear module boundaries, reliable events.
− Requires discipline (arch tests) to keep boundaries; outbox relay is an extra moving part to monitor.

## Verification
Arch test blocks cross-module internal imports; outbox duplicate delivery yields one inbox notification (doc 14 #14); Redis flush during load test loses no domain event; version table filled before Accepted.

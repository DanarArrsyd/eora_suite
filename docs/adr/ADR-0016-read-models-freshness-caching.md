# ADR-0016 — Read models, KPI freshness and caching

- **Status:** Proposed (Phase 3.5) · **Date:** 2026-10-09

## Context
Control Tower KPIs and aggregates must be fast (doc 20 W-03) yet traceable to the ledger and honest about staleness.

## Decision (proposed)
1. Aggregates (`ct.overview`, flow strip, daily FG receipt, daily delivery outstanding, pending QC aging, variance summary) are rebuildable read models maintained by idempotent outbox consumers; each row stores `as_of` and the ledger high-water mark.
2. API returns `meta.freshness {source, lagSeconds, stale, thresholdSeconds}`; UI shows FreshnessBadge and a stale banner above the tenant threshold (default 10 min).
3. Redis caches query results only, keyed by tenant + resource + scope hash + query signature, TTL ≤ threshold, invalidated per resource by events; posting decisions never read the cache.
4. Nightly verification against the ledger; rebuild command per model.
5. Detail lists stay live QueryDefinitions with keyset pagination; the overview never loads transaction rows.

## Consequences
+ Predictable dashboard latency; explicit freshness.
− Projector lag must be monitored (metric + alert).

## Verification
Rebuild equality tests, lag metrics in W-01/W-03, stale-state E2E (already covered in the frontend with `?simulate=stale`).

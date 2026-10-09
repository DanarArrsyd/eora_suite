# ADR-0013 — API contract conventions (versioning, OpenAPI, errors, idempotency)

- **Status:** Accepted with conditions for tooling (D-047: verify versions/compatibility before installing Scramble + openapi-typescript); conventions accepted as baseline with Phase 3.5 (D-045) · **Date:** 2026-10-09

## Context
Phase 1–3 frontend components consume typed `ListSource` / command adapters. The backend must expose contracts that keep the shared components unchanged and make every business number server-owned.

## Decision (proposed)
1. `/api/v1` path versioning; additive changes within a version; deprecation headers before removal.
2. Code-first **OpenAPI 3.1** generated from Laravel (candidate: Scramble) and typed client generation into `packages/api-client` (candidate: openapi-typescript + openapi-fetch) — exact tools/versions confirmed at Foundation (O-030).
3. Query grammar, envelope, cursor/offset policy and `counts=views` as in doc 24 §3.
4. Errors as RFC 9457 problem+json with a stable `code` catalogue (doc 24 §5), `correlationId` and user-facing `reference`; 404 for foreign-tenant ids.
5. Commands require `Idempotency-Key`; drafts use `If-Match` versions; posting conflicts return 409/412/503 as catalogued.
6. Quantities as decimal strings; dates `YYYY-MM-DD` (plant calendar); instants ISO UTC.

## Consequences
+ Frontend adapters are thin and generated; contract drift caught in CI (oasdiff).
− Decimal strings require one parsing step in adapters; tooling choice still to validate.

## Verification
OpenAPI diff gate; contract tests for each `ct.*` and transactional resource; frontend adapter tests with recorded fixtures (doc 24 §7).

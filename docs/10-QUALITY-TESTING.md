# 10 — Quality, Testing & Release Gates

## Pyramid
PHP unit/domain tests (Pest/PHPUnit), integration tests with real PostgreSQL transactions, API contract tests, TS unit/component tests (Vitest), Playwright E2E desktop/PWA, accessibility and visual regression, k6 performance, dependency/security scans, backup restore and disaster recovery exercise.

## High-risk tests
Concurrent RM issue from same lot; repeated requests/idempotency; cross-tenant URL/export access; revoked permissions; posting to closed period; approval maker-checker; multi-run/multi-lot WO; sheet/coil formula unit mismatch; partial QC; FG receipt only after release; delivery reservation concurrency; correction/reversal; stock count cut-off; outbox duplicate/retry; push unavailable; import duplicates; export filter equivalence; offline PWA conflicts.

## Release gates
No P0/P1 defects; passed end-to-end traceability; ledger vs projections reconciled; authorized UAT signoff; security and performance baseline documented; rollback/backup restore tested; monitoring and support runbook active; tenant isolation tested automatically. Pilot release feature-flagged and reversible.

## Definition of Done per feature
Approved business rule, acceptance scenarios, permission coverage, API schema, UI states, migration, unit/integration/E2E as applicable, audit, telemetry, documentation, performance consideration and reviewer signoff.

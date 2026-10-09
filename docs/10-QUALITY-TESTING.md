# 10 — Quality, Testing & Release Gates

## Pyramid
PHP unit/domain tests (Pest/PHPUnit), integration tests with real PostgreSQL transactions, API contract tests, TS unit/component tests (Vitest), Playwright E2E desktop/PWA, accessibility and visual regression, k6 performance, dependency/security scans, backup restore and disaster recovery exercise.

## High-risk tests
Concurrent RM issue from same lot; repeated requests/idempotency; cross-tenant URL/export access; revoked permissions; posting to closed period; approval maker-checker; multi-run/multi-lot WO; sheet/coil formula unit mismatch; partial QC; FG receipt only after release; delivery reservation concurrency; correction/reversal; stock count cut-off; outbox duplicate/retry; push unavailable; import duplicates; export filter equivalence; offline PWA conflicts.

## Release gates
No P0/P1 defects; passed end-to-end traceability; ledger vs projections reconciled; authorized UAT signoff; security and performance baseline documented; rollback/backup restore tested; monitoring and support runbook active; tenant isolation tested automatically. Pilot release feature-flagged and reversible.

## Definition of Done per feature
Approved business rule, acceptance scenarios, permission coverage, API schema, UI states, migration, unit/integration/E2E as applicable, audit, telemetry, documentation, performance consideration and reviewer signoff.

## Acceptance test strategy for the backend (Phase 3.5, PROPOSED)
| Layer | Tooling | Scope | Gate |
|---|---|---|---|
| Domain unit | Pest | rule objects (BR-*), formulas (doc 02, ADR-0005, BR-DEM, doc 23) with the doc 14 numbers as golden cases; **mandatory MPS regression doc 23 #65 (F-1 680 pcs, D-046)** | 100 % of BR-* with a test id |
| Posting engine integration | Pest + real PostgreSQL (Testcontainers/Docker) | every row of the posting matrix (doc 04): preconditions, legs, balances, idempotent replay, reversal, period closed | all 13 transaction types |
| Concurrency | Pest parallel processes / k6 | doc 20 §8 risks: oversell, deadlock, duplicate replay, numbering | zero duplicates, zero negative balances |
| Tenant isolation | Pest + pooler | ADR-0001 T-ISO-01..05 | must pass on every PR |
| Contract | OpenAPI diff + schema tests; frontend adapter tests against recorded fixtures | ADR-0013, doc 24 §7 | no undocumented breaking change |
| Parity | grid vs export rows (ADR-0008 verification) | each QueryDefinition | identical rows |
| Read models | rebuild equality vs ledger; lag | doc 19 §4 | rebuild = incremental |
| E2E | Playwright against API + seeded synthetic tenant | doc 14 scenarios end-to-end (R1 DoD) | green before pilot |
| Performance | k6 W-01..W-11 | doc 20 | SLOs accepted only after review (P-005) |
Seed data: synthetic generator (doc 20 §6) plus the doc 02/14 worked examples as named fixtures; never customer data.

## B0 backend test foundation (implemented 2026-10-09)
- Pest 5 suites: `Unit`, `Feature`, `Arch` (no debug calls, strict types and final controllers in `App\Platform`, platform never depends on modules) and the `integration` group (real PostgreSQL + Redis; CI services / Docker Compose).
- Tests always run on PostgreSQL (never SQLite); `composer test:unit` excludes `integration` when services are not running.
- CI jobs: `frontend` (unchanged), `backend` (composer validate + audit, Pint, Larastan level 8, boot, migrate, Pest, OpenAPI + typed-client drift), `docker` (compose config, `up --wait`, health/readiness).


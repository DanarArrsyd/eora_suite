# Daily Recovery Planner — local validation, 2026-11-18

Scope: D-134, doc 36, ADR-0019, BR-PLN-REC-01..03. Native entry: `/ppic/daily-recovery`.

## Results

| Check | Result |
|---|---|
| Full API suite, isolated PostgreSQL instance 1 | 488 passed, 1 skipped; 19,688 assertions |
| Full API suite, isolated PostgreSQL instance 2 with separate storage | 488 passed, 1 skipped; 19,688 assertions |
| Skip | Existing PoolingIsolationTest requires PgBouncer; these environments used direct PostgreSQL connections |
| API integration + unit checks | Real production/QC/FG receipt, shared-customer allocation before filtering, source drift, If-Match, retriable Draft WO creation, immutable linked revisions, plant/tenant scope and SQL authorization guards |
| Material capacity | Paired Plate Grip RH 300 + LH 350 consumes 20 sheets once; residual blanks RH 60 / LH 10 in the calculation; existing real paired-production integration passes |
| Migration round trip | `migrate:refresh --database=pgsql_owner --force` succeeded on an isolated database as non-superuser eora_owner |
| PHPStan | No errors |
| Pint, changed daily-recovery source/tests/migration | Passed |
| Web build and TypeScript | Passed |
| Frontend unit suite | 18 files, 130 tests passed |
| Browser regression | 12 tests passed across Daily Recovery, MPS, MRP and Work Orders |
| Accessibility/mobile | Axe WCAG checks passed; 390px viewport without page overflow; view-only controls disabled |
| Visual review | Desktop table and mobile detail reviewed using synthetic API fixtures |
| OpenAPI/client | Exported OpenAPI and regenerated TypeScript schema; API contract tests passed |
| Diff whitespace | `git diff --check` passed |

The build tool emitted a Next.js security-upgrade recommendation during validation; the retry completed successfully. Dependencies were not upgraded in this feature slice. This is an observed tooling warning, not an independently audited advisory assessment.

## Handoff boundary

Changes remain local on the existing feature branch. No push, merge or production deployment was performed. Operational Excel example rows were not imported as factory stock. Apply the approved migrations and tenant-template permission sync in the intended application environment before use; run the new M5 UAT steps with actual authorized records. Customer-owned material enforcement remains the separate doc 35 scope. Dedicated test containers and temporary credential files are removed after validation.

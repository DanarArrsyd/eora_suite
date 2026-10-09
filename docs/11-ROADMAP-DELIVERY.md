# 11 — Delivery Plan, Dependencies & Claude Workflow

## Stage 0: Discovery and scope freeze
Validate discrete manufacturing personas, actual process variants, source documents, business exceptions and decisions. Output: signed requirements baseline, gap register, process maps, acceptance examples.

## Stage 1: Solution design (NO production code)
Data ownership, ERD, state machines, RBAC, material formulas, reconciliation, API contracts, ADRs, UX research, three original mockup directions for desktop/PWA. **Explicit approval checkpoint** for architecture and UI.

## Stage 2: Foundation
Monorepo, auth/tenancy, RBAC, shared UI library, audit, outbox, database migrations, CI/CD, observability, test harness.

## Stage 3: End-to-end vertical slice
Customer PO → Sales Order line + delivery schedule (basic, D-035) → basic MPS / planned order → WO → RM issue to Production → actual consumption/remnant → good/NG → Pending QC → QC release → FG receipt → shipment → traceability → reconciliation case. Include 25-pcs unexplained variance and correction/approval path. Verify concurrency and audit.

## Stage 4: Expand V1
Forecast/kanban, basic capacity, substitutions, multi-lot, stock count/closing, basic maintenance, PWA, bell/push, Excel/PDF, PPIC Control Tower, dashboards and role-based workflows.

## Stage 5: Pilot and hardening
Security/performance testing, migration rehearsal, backup restore, UAT, onboarding, support, monitoring, pilot signoff. Only then wider rollout.

## Backlog format
Epic → story (`As [role], I need [capability], so [outcome]`) → business rule IDs → API/data dependency → UX component → acceptance criteria (Given/When/Then) → test → estimate/priority → status. No invented sprint dates or story points until team capacity known.

## Backend implementation roadmap (approved baseline D-045; each slice still needs explicit owner approval)
Each slice ends with tests (doc 10), migration + rollback plan, docs update and an owner review; local commits only.
| Slice | Content | Depends on | Exit |
|---|---|---|---|
| B0 Foundation **(COMPLETED, D-053: merged `c19be09`, post-merge CI green)** | Laravel 13 in `apps/api`, versions pinned (D-052), Docker Compose (PostgreSQL 18, Redis 8.6, API), health/readiness, problem+json, correlation ids, JSON logs, CI jobs (Pint, Larastan, Pest incl. arch + integration, OpenAPI/typed-client drift, Docker health), Scramble → `packages/api-client` | D-045, D-047 | green CI on GitHub; PgBouncer and the tenant isolation harness move to B1 (they need tenant context) |
| B1 Tenancy & access **(architecture review — doc 25; implementation NOT authorized, D-053)** | tenants/companies/plants/memberships/roles/scopes, Sanctum session + CSRF, TenantTransaction + RLS, audit, rate limits, business calendars (ADR-0012) | B0 | T-ISO-01..05 green |
| B2 Master data **(scope review — doc 26 adds routing header/versions/operations, process catalogue, machines/tooling, BOM–routing map; NOT authorized)** | items, UOM, specs, BOM (+ gross qty per, O-026), routings, conversion profiles, SY/NW versions, customers, suppliers, work centers, locations | B1 | import templates for master data |
| B3 Inventory posting engine | documents, legs, balances, allocations, genealogy, periods, numbering, idempotency, reversal (ADR-0014) — posting matrix rows 1–13 | B2 | posting-matrix + concurrency suites green |
| B4 Demand | Customer PO, SO header/lines, schedule lines, reallocation + exceptions (D-038), blanket (D-039), forecast + consumption (D-040) | B2 | doc 14 #28–#50 green |
| B5 Planning | WO + snapshots; basic MPS + MRP per D-046 (time-phased netting, safety stock status, lot-for-lot with lead time, BOM-based MRP, manual WO, capacity warnings) | B3, B4; O-015 for WO close | doc 23 #51–#68 green incl. #65 F-1 680 pcs regression |
| B6 Materials & Production | issue/transfer/return/split, production posting with measurement, outputs, scrap | B3, B5 | doc 14 #1–#5, #19–#24 green |
| B7 Quality, FG, Delivery | inspection, disposition, FG receipt, pick, shipment, POD | B6 | doc 14 #6, #7, #18, #25 green |
| B8 Reconciliation | engine (doc 02), variance results, cases, approvals | B6 | ADR-0005 checks green |
| B9 Read models & Control Tower API | `ct.*` resources, freshness, cache (ADR-0016); frontend adapters swap mocks for the typed client | B4–B8 | CT screens on real data, parity tests |
| B10 Hardening | k6 W-01..W-11, backup/restore drill, security review | B9 | pilot readiness review |
Open-decision dependencies per slice: register "Remaining OPEN decisions" table (doc 13) — O-007 → B1/B3/B7, O-012 → B8, O-013 → B6, O-015 → B5/B6, O-021 → pilot release. Slices build these as configuration without defaults until the owner decides.
Source control: GitHub `DanarArrsyd/eora_suite` (private), branch `main` (D-050); pushes only on owner instruction; CI results reported only after a GitHub runner finishes.


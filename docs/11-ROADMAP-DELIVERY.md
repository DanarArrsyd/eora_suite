# 11 — Delivery Plan, Dependencies & Claude Workflow

## Stage 0: Discovery and scope freeze
Validate discrete manufacturing personas, actual process variants, source documents, business exceptions and decisions. Output: signed requirements baseline, gap register, process maps, acceptance examples.

## Stage 1: Solution design (NO production code)
Data ownership, ERD, state machines, RBAC, material formulas, reconciliation, API contracts, ADRs, UX research, three original mockup directions for desktop/PWA. **Explicit approval checkpoint** for architecture and UI.

## Stage 2: Foundation
Monorepo, auth/tenancy, RBAC, shared UI library, audit, outbox, database migrations, CI/CD, observability, test harness.

## Stage 3: End-to-end vertical slice
Customer PO → WO → RM issue to Production → actual consumption/remnant → good/NG → Pending QC → QC release → FG receipt → shipment → traceability → reconciliation case. Include 25-pcs unexplained variance and correction/approval path. Verify concurrency and audit.

## Stage 4: Expand V1
Forecast/kanban, basic capacity, substitutions, multi-lot, stock count/closing, basic maintenance, PWA, bell/push, Excel/PDF, PPIC Control Tower, dashboards and role-based workflows.

## Stage 5: Pilot and hardening
Security/performance testing, migration rehearsal, backup restore, UAT, onboarding, support, monitoring, pilot signoff. Only then wider rollout.

## Backlog format
Epic → story (`As [role], I need [capability], so [outcome]`) → business rule IDs → API/data dependency → UX component → acceptance criteria (Given/When/Then) → test → estimate/priority → status. No invented sprint dates or story points until team capacity known.

# EORA Suite V1 — Project Blueprint & Claude Code Handoff

**Status:** Specification and UI baseline approved (doc 13, D-027). **Frontend implementation authorized in phases** (D-033, doc 22) — Phases 1–3 approved (Control Tower frontend on typed mocks). **Phase 3.5 Backend Architecture & Data Contract Readiness approved** (D-045) as the backend baseline; backend slices (doc 11) start only with explicit owner approval per slice — **B0 Foundation completed** (D-053, `apps/api`, merged `c19be09`); **M0 decisions recorded 2026-10-11** (doc 27, D-054..D-065): B1/B2 decisions approved, D-B1-24 signed tenant context approved; delivery in milestones M1–M7 (doc 11), each starting only after its scope approval. Source: private GitHub repository `DanarArrsyd/eora_suite`, branch `main` (D-050). **Industry:** Discrete manufacturing (stamping, machining, fabrication, welding, assembly). **Product:** independent multi-tenant SaaS Manufacturing Operations Management (MOM). **Timezone default:** Asia/Jakarta, tenant-configurable. **Last consolidated:** 2026-10-11.

## Read in this order
1. `CLAUDE.md` for Claude Code; `AGENTS.md` for other coding agents.
2. `docs/00-MASTER-BLUEPRINT.md` — approved direction and unresolved decisions.
3. `docs/01-BUSINESS-PROCESS.md` and `docs/02-MATERIAL-CONVERSION-RECONCILIATION.md` — transaction correctness.
4. `docs/03-MODULE-SPECIFICATIONS.md` through `docs/12-SAAS-OPERATIONS.md`.
5. `docs/13-DECISION-REGISTER.md` and `docs/14-ACCEPTANCE-SCENARIOS.md`.
6. `docs/adr/` (ADR-0001..0016), `docs/15-BUSINESS-RULE-CATALOG.md`, `docs/16-STATE-MACHINES.md`, `docs/17-DATA-MODEL-ERD.md`, `docs/18-RBAC-OWNERSHIP-MATRIX.md`, `docs/19-PPIC-CONTROL-TOWER-SPEC.md`, `docs/20-PERFORMANCE-WORKLOAD-MODEL.md`, `docs/21-PRODUCT-BACKLOG.md`, `docs/23-MPS-PLANNING-SPEC.md`, `docs/24-API-CONTRACT-SPEC.md`.
7. `docs/uiux/` — approved design system (canonical UI/UX source, D-021) and `design/mockups/` — Modern Corporate Blue prototypes (static, sample data); prototype v2.1 is the official design baseline (D-027). Superseded v1 prototypes in `design/mockups/_superseded-v1-ledger-ink/`.
8. `docs/22-FRONTEND-ARCHITECTURE.md` + ADR-0011 — frontend structure, phase plan, quality gates; code in `apps/web` and `packages/*`.
9. `apps/api/README.md` — Laravel API (B0 foundation): versions, Docker Compose, quality commands, OpenAPI → `packages/api-client`.
10. `docs/25-B1-TENANCY-SECURITY-PLAN.md` (B1, decisions approved D-055/D-057) and `docs/26-B2-MASTER-DATA-ROUTING-ADDENDUM.md` (B2, decisions approved D-058) — implementation per milestone approval (D-054).
11. `docs/27-M0-DECISION-SHEET.md` — M0 decision sheet (answered 2026-10-11).
12. `docs/28-M1-SCOPE.md`, `docs/29-M2-SCOPE.md`, `docs/32-M3-SCOPE.md` — milestone scope sheets (M1 approved D-066 and accepted D-071; M2 approved D-072 and closed D-095; M3 proposed, not approved).
13. `docs/30-CHANGE-PLAYBOOK.md` — how every later feature, rule, screen or permission change is made (D-077).
14. `prompts/CLAUDE-CODE-START.md` — copy/paste kickoff prompt.

## Non-negotiable distinctions
- **No WIP module or WIP label in operational UI.** Issued but unconsumed stock remains **Material at Production**; actual intermediate products may be tracked as **Semi-Finished Material**. This is a terminology/product-design decision, **not** permission to hide physical balances or accounting obligations.
- **Material issued ≠ material consumed.** Theoretical output ≠ physical stock. Unexplained yield variance ≠ proven physical loss.
- Production Good output ≠ QC Accepted ≠ FG Warehouse Received ≠ FG Available ≠ Delivered.
- One authoritative inventory movement ledger and controlled balance projections; no independent stock balances by module.
- Design reusable filter/table/form/report components centrally; preserve task-specific layouts.

## Delivery sequence
**Specification review → open-decision resolution → BPMN/state machines/ERD/API/RBAC → 3 desktop+PWA mockup directions → approval → foundation → end-to-end vertical slice → hardening/pilot.** No production implementation until explicit approval.

The `LEGACY-BLUEPRINT-REFERENCE.md` is preserved for context, but newer documents in this package override any conflicts, especially references to WIP or older scope. No fabricated KPI or operational data.

## Claude Code skill requirement
Kickoff requires discovery and reading of installed **Caveman** and **Frontend** skills. See `CLAUDE.md` for the mandatory skill-use report and fallback if either skill is unavailable.

**Every frontend/UI task must review the approved EORA Design System (`docs/uiux/`) and use relevant installed Caveman and Frontend Design skills.**

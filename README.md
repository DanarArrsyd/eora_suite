# EORA Suite V1 — Project Blueprint & Claude Code Handoff

**Status:** Planning baseline; not approved for coding. **Industry:** Discrete manufacturing (stamping, machining, fabrication, welding, assembly). **Product:** independent multi-tenant SaaS Manufacturing Operations Management (MOM). **Timezone default:** Asia/Jakarta, tenant-configurable. **Last consolidated:** 2026-10-08.

## Read in this order
1. `CLAUDE.md` for Claude Code; `AGENTS.md` for other coding agents.
2. `docs/00-MASTER-BLUEPRINT.md` — approved direction and unresolved decisions.
3. `docs/01-BUSINESS-PROCESS.md` and `docs/02-MATERIAL-CONVERSION-RECONCILIATION.md` — transaction correctness.
4. `docs/03-MODULE-SPECIFICATIONS.md` through `docs/12-SAAS-OPERATIONS.md`.
5. `docs/13-DECISION-REGISTER.md` and `docs/14-ACCEPTANCE-SCENARIOS.md`.
6. `prompts/CLAUDE-CODE-START.md` — copy/paste kickoff prompt.

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

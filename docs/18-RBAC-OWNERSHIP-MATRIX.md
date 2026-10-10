# 18 — RBAC Matrix and Data Ownership

Roles are **default templates** a tenant may copy and adjust (doc 05); permissions are the enforced unit. Every grant is evaluated with scope `tenant + company + plant + (warehouse | work_center)`. **D-060 (2026-10-11):** the templates in §1 are the default role templates; no quantity thresholds in early V1 — every sensitive action (reversal, adjustment, QC release beyond policy, version approvals) requires an independent second approver; thresholds become tenant configuration later; no vendor-support impersonation in V1.

Legend: **V** view · **C** create/edit draft · **S** submit · **P** post/execute · **A** approve (maker ≠ checker) · **R** request reversal/correction · **X** export · — none. Scope column = default data scope of the template.

## 1. Role templates
| Code | Role | Default scope |
|---|---|---|
| PSA | Platform Super Admin (vendor) | platform only; no tenant data |
| CSA | Company Super Admin | tenant/company |
| MAD | Module Admin | tenant, assigned modules |
| PPC | PPIC Planner | plant |
| MAT | Materials PIC (RM warehouse) | plant + assigned warehouses |
| PRL | Production Leader | plant + assigned work centers |
| OPR | Production Operator | assigned work centers, own runs |
| QCI | Quality Inspector | plant |
| QCS | Quality Supervisor | plant |
| FGW | FG Warehouse | plant + FG warehouses |
| DLV | Delivery | plant |
| INV | Inventory Controller | plant |
| APR | Approver (add-on role) | as assigned |
| AUD | Auditor/Viewer | tenant read-only |

## 2. Permission matrix (core vertical slice)
| Permission (resource.action) | CSA | PPC | MAT | PRL | OPR | QCI | QCS | FGW | DLV | INV | AUD |
|---|---|---|---|---|---|---|---|---|---|---|---|
| demand.po | V | VCS P(revise) | V | — | — | — | — | V | V | — | V |
| planning.wo | V | VCSP | V | V | V(own WC) | V | V | V | V | V | V |
| planning.control_tower | V | V X | V | V | — | V | V | V | V | V | V |
| materials.issue | V | V | VCSP | V (accept custody P) | — | — | — | — | — | V | V |
| materials.return | V | V | VP (receive) | VCS | VC | — | — | — | — | V | V |
| production.run | V | V | V | VCSP | VCS (own) | V | V | — | — | V | V |
| production.posting (consume/output/scrap) | V | V | V | VCSP | VCS | V | V | — | — | V | V |
| production.lot_split (remnant) | V | V | V (accept) | VCSP | VC | — | — | — | — | V | V |
| quality.inspection | V | V | — | V | — | VCSP | VCSP | V | — | V | V |
| quality.disposition.release | V | V | — | — | — | P (≤ threshold) | P A | V | — | V | V |
| warehouse.fg_receipt | V | V | — | — | — | V | V | VCSP | V | V | V |
| delivery.allocate/pick/ship | V | V | — | — | — | — | — | VP (pick) | VCSP | V | V |
| inventory.stock_count | V | V | VC (count) | VC (count WC) | — | — | — | VC (count) | — | VCSPA | V |
| inventory.adjustment | R A | — | R | R | — | — | — | R | — | VCS R | V |
| inventory.reversal | R A | R | R | R | — | R | R | R | R | R A | V |
| inventory.period.close | A | — | — | — | — | — | — | — | — | VP | V |
| inventory.period.reopen | R | — | — | — | — | — | — | — | — | — | V |
| recon.case | V A | V | V | VC (evidence) | — | V | V | — | — | VCS A | V |
| core.approval (any) | A | — | — | — | — | — | A(QC) | — | — | A | — |
| core.users_roles | VCP | — | — | — | — | — | — | — | — | — | V |
| core.audit | V X | — | — | — | — | — | — | — | — | V | V X |
| exchange.export (per resource) | X | X | X | X | — | X | X | X | X | X | X |
| exchange.import (per template) | P A | C S | C S | — | — | — | — | — | — | C S | — |

APR role adds `A` on configured entities; it never adds `P` on the same document it approves.

## 3. Separation-of-duties rules (enforced in policy layer)
| SoD-ID | Conflict | Rule |
|---|---|---|
| SoD-01 | Production output reporter vs QC release of same lot | different user (BR-QC-03) |
| SoD-02 | Adjustment/reversal maker vs approver | different user, step-up auth (BR-APR-01) |
| SoD-03 | Stock counter vs count variance approver | different user (BR-CNT-03) |
| SoD-04 | Issuer vs custody acceptor (two-step) | different user (BR-ISS-03) |
| SoD-05 | Case owner vs case reviewer/approver | different users (doc 16 §7) |
| SoD-06 | Role administrator granting self elevated rights | blocked; second CSA approval |
Break-glass: time-boxed grant, reason, approver, auto-expiry, full audit (doc 05).

## 4. Data ownership matrix (who writes, who reads)
| Entity | Owning module (sole writer) | Writes via | Readers | Cross-module change path |
|---|---|---|---|---|
| Customer PO, releases, forecast, demand consumption | Demand | Demand services | Planning, Delivery, Analytics | event `demand.changed` |
| Work Order, planning snapshots | Planning | Planning services | Production, Materials, Quality, Analytics | `wo.*` events |
| Conversion profiles, SY, NW (engineering data) | Planning (Engineering master) | versioned master data service | Production, Reconciliation | versioning only, no retro edit |
| Material issue/return/custody documents | Materials | Materials services → Inventory posting contract | Production, Inventory, Analytics | `materials.issued` |
| Production runs, measurements, postings (facts) | Production | Production services → Inventory posting contract | Quality, Reconciliation, Analytics | `production.output_posted` |
| Inspection lots, dispositions | Quality | Quality services → Inventory status movement | Warehouse, Planning, Analytics | `qc.disposed` |
| FG receipt, putaway, pick, handling units | Warehouse | Warehouse services → Inventory posting contract | Delivery, Planning | `fg.received` |
| Delivery orders, shipments | Delivery | Delivery services → Inventory posting contract | Demand (outstanding), Analytics | `shipment.posted` |
| Ledger, balances, allocations, periods, counts, adjustments | **Inventory** | `InventoryPostingService` only | all (read) | — |
| Genealogy edges | Inventory (written with posting) | posting service | Traceability | — |
| Variance results, recon cases | Traceability (Reconciliation) | Reconciliation service | Planning, Production, Analytics | `recon.case_opened` |
| Users, roles, approvals, audit, notifications, files, import/export jobs | Core | Core services | all (scoped) | — |
| Read models / dashboards | Analytics | outbox consumers (rebuildable) | all (scoped) | never written by users |
| Sales orders, schedule lines, reallocations, demand exceptions *(added 2026-10-09)* | Demand | Demand services | Planning, Delivery, Analytics | `demand.schedule_changed`, `demand.exception_opened` |
| MPS versions/lines/pegs, planned orders, material requirements | Planning | Planning services | Production, Materials, Analytics | `mps.approved`, `planned_order.released` |
| Business calendars (tenant default, plant override) | Core | calendar service (versioned) | all | `calendar.changed` (rebuild buckets) |
| Substitution approvals | Materials (engineering approval) | Materials services | Production, Planning | `substitution.approved` |
| Downtime events, rework orders | Production | Production services | Planning, Maintenance, Analytics | `production.downtime_recorded` |
| Inspection results, quality holds | Quality | Quality services | Production, Planning | `qc.hold_raised` |
| Delivery confirmations (POD) | Delivery | Delivery services | Demand, Analytics | `delivery.confirmed` |
| Correction requests, idempotency records | Core | Core services | owning module of target | `correction.approved` → owning module executes reversal |

Rule: no module writes another module's tables; cross-module reads use contracts/query definitions; cross-module **read** permission never implies **post** permission (doc 03).

## 5. Phase 3.5 additions (2026-10-09, PROPOSED until backend approval)
| Permission (resource.action) | CSA | PPC | MAT | PRL | QCI | QCS | FGW | DLV | INV | AUD |
|---|---|---|---|---|---|---|---|---|---|---|
| demand.sales_order / schedule | V | VCSP | — | — | — | — | V | V | — | V |
| demand.reallocation (D-038) | V | VCS (P after confirmation) | — | — | — | — | — | — | — | V |
| demand.exception | V | VCP (resolve) | — | — | — | — | — | V | — | V |
| planning.mps | V A | VCS (preparer ≠ approver, SoD-07) | V | V | — | — | — | — | — | V |
| planning.planned_order.release | V | VCP | V | V | — | — | — | — | — | V |
| core.calendar | VCP | V | V | V | V | V | V | V | V | V |
SoD-07 *(proposed)*: MPS preparer cannot approve the same MPS version.
Evaluation order for every request: authenticated session → active tenant membership (session) → permission in any role assignment → **scope match** (assignment scope contains the record's company/plant/warehouse/work center) → resource policy (state, SoD, thresholds) → RLS backstop. List endpoints apply the scope as query predicates (QueryDefinition), never as post-filtering.


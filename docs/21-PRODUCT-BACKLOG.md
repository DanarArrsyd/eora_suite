# 21 — Product Backlog (full V1 scope, release-tagged)

**Decision:** D-017 (AG-09). Iteration 1 = Core Vertical Slice (R1). The full V1 scope remains here; no module is removed. Release tags: **R1** vertical slice · **R2** V1 additions · **V2** later. No dates or story points until team capacity is known (doc 11). Status for all items: `Backlog` (implementation not approved yet).

## Epics
| Epic | Module | R1 (vertical slice) | R2 (V1 additions) | V2 / deferred |
|---|---|---|---|---|
| E-01 Tenancy & identity | Core | tenant/company/plant, membership, session auth (ADR-0006), tenant switch, RLS + isolation suite | MFA/step-up for approvals, SSO | support impersonation policy (O-007) |
| E-02 RBAC & scopes | Core | role templates, permission catalogue, scope assignment, SoD-01/02 | SoD-03..06, break-glass, access review | — |
| E-03 Audit & approval | Core | audit events, approval engine (policy table), maker-checker for reversal/adjustment | escalation/expiry, Data Correction Center UI | hash-chain audit (DF-02) |
| E-04 Master data | Core/Planning | items, UOM, material specs, BOM/routing versions, CP/SY/NW versions, work centers, machines/dies, locations incl. virtual | import templates for master data | serial tracking |
| E-05 Customer demand | Demand | customer PO + lines + revisions; **Sales Order basic (D-030, D-035): PO-to-SO mapping, SO header/lines, delivery schedule lines, revision/status, partial fulfilment, outstanding; demand identity + double-count guard (D-034, doc 14 #28–#39)** | forecast + consumption (same-bucket matching, D-040), kanban release UI (bucket decided: weekly + daily, D-041) | advanced forecast consumption windows | blanket order advanced, full sales ERP |
| E-06 Work order & planning | Planning | WO create/release/snapshot/close (BR-PLN-*); **basic MPS (D-031, D-035): demand aggregation, daily/weekly horizon, FG availability, scheduled supply, net requirement, basic material + capacity check, planned order → WO linkage** | MPS approval workflow refinements, MRP explosion depth beyond one level | APS optimisation, AI scheduling (~~MRP-lite V2~~ superseded by D-031) |
| E-07 Inventory ledger | Inventory | documents, legs, balances, allocations, negative-stock guard, idempotency, reversal, periods | stock count/blind count, period close UI | consignment/customer-owned (O-005) |
| E-08 Materials | Materials | RM receipt (with BLOCKED option), issue, return, lot split/remnant | two-step custody, substitution approval, incoming release (O-013) | full procurement, incoming inspection |
| E-09 Production | Production | run, measurement capture, Production Posting (all 6 categories), multi-shift | rework order, downtime, die stroke | IoT counters |
| E-10 Quality | Quality | inspection lot, partial disposition, SoD-01 | hold workflow, defect codes, sampling | NCR, CAPA, supplier quality |
| E-11 FG warehouse | Warehouse | FG receipt, putaway to bin | handling unit/polybox, pick/pack | — |
| E-12 Delivery | Delivery | delivery order, allocation, shipment, partial shipment | proof of delivery, delivery note print | customer returns detail |
| E-13 Reconciliation | Traceability | variance results GYV/PvS/UMV (ADR-0005), cases, 25-pcs scenario | tolerance hierarchy UI, evidence attachments | — |
| E-14 Traceability | Traceability | backward/forward trace (table view) | genealogy graph view | — |
| E-15 Control Tower | Planning/Analytics | CT-0, CT-1, CT-3, CT-6 minimal (doc 19); **frontend for CT-0..CT-10 built on typed mocks in Phase 3 (D-037) — backend read models still R1 backlog** | CT-2, CT-4, CT-5, CT-7, CT-8 full backend; expected-acceptance policy for Pending QC projection | advanced daily planning / optimisation (D-041) |
| E-16 Notifications | Core | outbox, inbox, bell (in-app) | realtime Reverb, Web Push, preferences, quiet hours | email connector |
| E-17 Import/Export/Print | Core | QueryDefinition + XLSX export for slice resources | import wizard, PDF templates, watermark, job center | document verification QR |
| E-18 PWA Floor Mode | Web | responsive task views for issue, production posting, QC | installable PWA, scanner, offline drafts (O-009) | — |
| E-19 Maintenance | Maintenance | — | assets/dies availability, breakdown, basic PM | predictive |
| E-21 Frontend phases (D-033) | Web | Phase 1 foundation (approved, D-036), Phase 2 shared patterns (approved, D-037), Phase 3 Control Tower UI (authorized, D-037) | Phase 4 reconciliation UI, Phase 5 PWA foundation | — |
| E-22 Backend readiness (D-042) | Platform | Phase 3.5 design deliverables (docs 04, 17, 23, 24, ADR-0012..0016); backend slices B0–B10 (doc 11) after approval | — | — |
| E-20 Ops & quality gates | Platform | CI, arch tests, isolation tests, observability baseline | k6 scenarios W-01..W-11, backup/restore drill | — |

## R1 vertical slice — definition of done
Customer PO → Sales Order line + delivery schedule → basic MPS / planned order → WO release → RM issue to Material at Production → measured consumption + remnant + return → Good / NG Rework / NG Rejected / Scrap → Pending QC → partial QC release → FG receipt → partial shipment → backward trace → reconciliation case for the 25-pcs sheet scenario with maker-checker resolution. Acceptance: doc 14 #1–#7, #10–#12, #17–#23, #28–#39 automated; isolation suite green; no P0/P1 defects.

## Story template
`As [role], I need [capability], so [outcome]` → rule IDs (doc 15) → API/data dependency (doc 17) → UX component (ADR-0009) → Given/When/Then → tests → priority → status.

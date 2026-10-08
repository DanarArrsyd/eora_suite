# 13 — Decision Register and Open Questions

Authority for product/technical decisions. Change control rules at the bottom. Prior rationale is preserved; superseded items are marked, never deleted.

## DECIDED (from project discussion)
- D-001: EORA Suite is independent commercial product, multi-tenant SaaS, target **discrete manufacturing** V1.
- D-002: **No WIP operational module/menu/label**; use Material at Production, Remaining Material and Semi-Finished Material where needed; still trace intermediate physical quantities.
- D-003: Single authoritative inventory ledger; no independent stock totals by module.
- D-004: PPIC Inventory & Fulfillment Control Tower; coil/sheet estimated pcs, QC pending, FG daily receipt, daily delivery and material variance.
- D-005: Excel import/export, PDF/print and EORA document branding; desktop + PWA, persistent bell and optional push.
- D-006: Shared design system and consistent schema-driven filters; role-aware UX.
- D-007: Sensitive posted transactions reversed/adjusted with audit and approval; no silent deletion.
- D-008: Before implementation, audit specifications, create three mockup directions and request explicit approval.

## DECIDED — Approval Gate AG-01..AG-12 (owner decision, 2026-10-08)

| ID | Gate | Decision | Conditions / revisions | ADR / spec |
|---|---|---|---|---|
| D-009 | AG-01 | **Shared PostgreSQL database**, tenant isolation by `tenant_id` on every tenant-owned row, composite FKs `(tenant_id, id)`, application-level authorization, **PostgreSQL RLS as defense-in-depth**. | Connection pooling and background workers must not leak tenant context; automated cross-tenant isolation tests mandatory. | ADR-0001 |
| D-010 | AG-02 | **Actual consumption requires independent measurement** (weighing, physical count, remnant measurement). | Consumption by difference allowed only as `UNMEASURED/PROVISIONAL` and automatically opens an investigation case. | ADR-0003, BR-CON-* |
| D-011 | AG-03 | Output categories are **separate**: Good Output, NG Reworkable, NG Rejected, Process Scrap, Reusable Remnant, Material Return. | Each has base UOM, ownership and ledger rule; consumption and output transformation never cause double deduction. | ADR-0004, BR-OUT-* |
| D-012 | AG-04 | Three separate indicators: **Gross Yield Variance**, **Performance vs Standard**, **Unexplained Material Variance**. | Formula, unit, rounding, tolerance and finality conditions defined. | ADR-0005, doc 02 |
| D-013 | AG-05 | Material-Based Production Control, no WIP module. Material at Production recorded by **location/work center + lot + WO allocation**; unconsumed material remains material inventory. | Two-step custody acceptance configurable per tenant. | ADR-0002, BR-ISS-* |
| D-014 | AG-06 | Explicit stock and QC statuses. Pending QC never = Available FG. **QC Accepted and FG Receipt are distinct transactions.** Reservation never changes physical on-hand. | — | ADR-0002, doc 16 |
| D-015 | AG-07 | **Negative stock prohibited.** Closed periods never modified directly; corrections via reversal/adjustment in an open period with approval and immutable audit. | Not tenant-configurable (ledger semantics, doc 12). | ADR-0002, BR-PER-* |
| D-016 | AG-08 | **Next.js + React + TypeScript** web/PWA, **Laravel API**, **Sanctum cookie-based** authentication. | Secure deployment topology and cookie/CSRF/CORS strategy; shared design system. | ADR-0006 |
| D-017 | AG-09 | Implementation starts with **Core Vertical Slice**. | Full V1 scope stays documented in backlog; no module removed for not being in iteration 1. | doc 21 |
| D-018 | AG-10 | ~~**Hybrid UI**: Desktop = Direction 1 "Ledger Ink"; Floor Mode = Direction 2 "Andon Signal"; `MaterialFlowStrip` from Direction 3 "Traceline".~~ **SUPERSEDED by D-021 (2026-10-08)** — owner rejected the visual direction after reviewing the mockups. | — | ADR-0009 (superseded) |
| D-019 | AG-11 | Baseline performance test assumption: **20 tenants × 150 users, 30% concurrency, 5,000 postings/day/tenant**. | Workload model adds peak shift, transaction mix, dashboard, import/export, notification fan-out, stock-posting concurrency. Not a product maximum. | doc 20 |
| D-020 | AG-12 | `git init` and local baseline commit of approved documents permitted. | No push, force push, history rewrite, or changes to other repositories without permission. | — |

## DECIDED — UI/UX redesign (owner decision, 2026-10-08)

| ID | Decision | Conditions | ADR / spec |
|---|---|---|---|
| D-021 | **EORA Modern Corporate Enterprise Workspace**, single theme **Modern Corporate Blue** (owner palette), **Inter** typography, **dual sidebar** desktop navigation (primary icon modules + grouped secondary menu), topbar with breadcrumb/search/company-plant/bell/profile, mobile PWA with bottom navigation, shared design system (shadcn/ui, Radix, Tailwind, TanStack, Lucide). No dark mode or theme switching. Owner inventory-ERP screenshot is the structural reference (adapted, not copied). Supersedes D-018. | Mockups must receive **explicit final owner approval before production frontend**. Business rules, formulas, ledger and state machines unchanged. | ADR-0010, `docs/uiux/*`, `design/mockups/01–04` |
| D-022 | Permanent rule: **every frontend/UI task must review the approved EORA Design System and use relevant installed Caveman and Frontend Design skills**; missing skills are reported, never claimed; owner decisions win over skill defaults. | Recorded in CLAUDE.md, AGENTS.md, README, constitution §6. | EORA-DESIGN-CONSTITUTION.md |
| D-023 | Token adjustments for accessibility within the palette: add `--color-border-control #7F8EA3` (3.33:1); muted `#94A3B8` only for disabled/decorative; badges use darker status foregrounds; table headers on `#F8FAFC`. | Owner may revise. | EORA-DESIGN-TOKENS.md §4 |

## DECIDED — UI/UX approval gate follow-up (owner decision, 2026-10-08)

| ID | Decision | Conditions | ADR / spec |
|---|---|---|---|
| D-024 | Terminology: **Material Variance** for menus and general indicators; **Finished Goods** (not "Finish Good"); consistent across modules. **Actual Material Loss** (investigation-proven, evidence + independent approval + stock adjustment, case resolution `ACTUAL_MATERIAL_LOSS_CONFIRMED`) and **Physical Stock Discrepancy** (approved stock count) are kept as distinct, proven outcomes — never inferred from unexplained variance. | Sales Orders (O-011) and MPS (O-014) remain pending business decisions — neither approved nor removed. | BR-REC-02, doc 16 §7, ADR-0005, doc 02, doc 19, constitution §4 |
| D-025 | Navigation indicators: **numeric badges only for items requiring the user's action** (approvals, QC inspections, variance investigations, assigned tasks, action-required notifications). No total record counts. Permission-aware, tenant-scoped, computed from actual data server-side. | Supersedes amber attention dot. | EORA-NAVIGATION-ARCHITECTURE.md §10 |
| D-026 | UX refinement set: responsive topbar priorities, mobile navigation sheet (backdrop, close, Esc, focus management, scroll lock, accessible state), DataTable Comfortable/Compact presets with shared configuration, grouped quantity columns, filter bottom sheet, rendered-page accessibility verification. | Prototype/spec only. | ADR-0010 Amendment 1, EORA-COMPONENT-STANDARDS.md |

### Promotion of earlier PROPOSED items
- P-001 → **DECIDED** by D-016 (frontend/auth) + D-009 (database). Reverb, Redis/Horizon, S3-compatible storage and Docker remain PROPOSED until ADR-0007 version/compatibility check at Foundation stage.
- P-002 → **DECIDED-DEFAULT**: pilot one plant per tenant, multi-plant-ready schema (no separate owner gate; contained in D-009/D-013 model).
- P-003 → **DECIDED** by D-013.
- P-004 → **DECIDED** by D-014; state machines in doc 16.
- P-005 → **PROPOSED**, refined by D-019 and doc 20; SLO acceptance pending real environment.

## DECIDED-DEFAULT (derived from approved gates; owner may override)
These were proposed in the audit and are required to make D-009..D-019 implementable. Treat as decided unless the owner reverses them.
- DD-01: Stock status catalogue `UNRESTRICTED, PENDING_QC, QC_RELEASED, HOLD, REWORK, REJECTED, BLOCKED` (doc 04, ADR-0002).
- DD-02: Double-leg movement ledger; non-transformation documents conserve quantity per item/lot/UOM; transformation documents reconciled by variance metrics (ADR-0002).
- DD-03: Reservation/allocation is a separate table, never a ledger movement (D-014).
- DD-04: Reusable Remnant = **lot split** of unconsumed material (child lot), not a transformation output (ADR-0004).
- DD-05: Same-origin deployment: Next.js and Laravel behind one host; `/api/*` routed to Laravel; no cross-origin CORS in production (ADR-0006).
- DD-06: Grid, export and Control Tower share one backend `QueryDefinition` per resource (ADR-0008).
- DD-07: Active tenant/plant context bound to server session, never to client-supplied header (ADR-0001).
- DD-08: Production date and shift are stored explicitly on every posting, derived from tenant shift calendar at posting time.

## OPEN — must not silently assume
- O-001: Actual coil/sheet conversion data: units of pitch, strip layout, thickness, width, density, cavities, setup/tail losses, weighbridge precision and measurement source. *(Formulas now fixed in doc 02; data still required per pilot item.)*
- O-002: ~~Material scrap accounting~~ → **Resolved by D-011/ADR-0004** except: salvage/recycle sale treatment and default tolerance values per process remain OPEN.
- O-003: Intermediate stage tracking and semi-finished material part numbering for stamping→welding→assembly.
- O-004: ~~QC stock physical location~~ → **Resolved by D-014/DD-01** (QC Area location, Production custody until QC disposition). Remaining OPEN: whether Quality takes custody of REJECTED/HOLD items in a separate quarantine location per tenant.
- O-005: Multiple plants, subcontracting, customer-owned material/consignment, returns and external process V1 policy.
- O-006: Material stock count freeze vs snapshot (audit default: snapshot + blind count) and shift boundary crossing midnight.
- O-007: Approval thresholds and default role templates *(matrix drafted in doc 18; thresholds still owner input)*; multi-tenant vendor support access/impersonation policy.
- O-008: Hosting/provider, region, data residency (incl. Indonesian personal-data regulation review), SLO/RPO/RTO.
- O-009: Pilot data migration, barcode/QR label standard, device/browser matrix, offline draft scope (proposed V1: production report draft and stock count draft only).
- O-010: Logo/brand tokens, PDF watermark/print attribution policy. *(UX direction resolved by D-021; final mockup approval pending.)*
- O-016: **CONDITIONALLY APPROVED (2026-10-08)** as design baseline. UX risks resolved in prototype v2.1 (D-026); **final approval of the refined prototypes is still required before production frontend.**
- ~~O-017~~ → **DECIDED as D-024** (approved with conditions, 2026-10-08).
- ~~O-018~~ → **DECIDED (2026-10-08)**: bell and profile at the right of the topbar; primary sidebar for modules only; secondary sidebar for grouped features.
- O-011: Demand netting rule: forecast consumption bucket (proposed weekly), kanban as release against blanket PO line, and whether Sales Order is a separate entity in V1 (proposed: no).
- O-012: Default variance tolerance when tenant has not configured one (proposed: strict — any non-zero final variance opens a case).
- O-013: RM incoming inspection: V1 minimal `BLOCKED` on receipt + manual release (proposed) vs full incoming inspection.
- O-014: Legacy features without decision: Data Correction Center (proposed IN, Core), NCR (V2), blind count (IN with stock count), handling unit/polybox (IN, Warehouse), document verification QR (V2), step-up MFA (IN for approvals), OEE (OUT until reliable data), MPS/MRP-lite (basic net requirements IN, MRP-lite V2).
- O-015: Leftover Material at Production at WO close: proposed rule = return, transfer to another WO allocation, or close with approved exception.

## DEFERRED
- DF-01: Export of Material at Production + Semi-Finished quantities at cut-off for external GL/ERP (quantity only; no valuation in V1).
- DF-02: Tamper-evident hash chain on `audit_events`.
- DF-03: Inter-company transfers within one tenant.

## Change log
| Date | Change | Owner | Affected |
|---|---|---|---|
| 2026-10-08 | Baseline register D-001..D-008, P-001..P-005, O-001..O-010 | Product owner | All |
| 2026-10-08 | UI approval gate follow-up: O-016 conditionally approved; O-017 → D-024; O-018 decided; D-025 badge policy; D-026 UX refinements (ADR-0010 Amendment 1); git commits permitted locally (no push) | Product owner (decision), Claude Code (record) | docs 02, 15, 16, 19, ADR-0005, ADR-0010, uiux/*, design/mockups v2.1 |
| 2026-10-08 | UI redesign: D-021 supersedes D-018; D-022 skill rule; D-023 token contrast adjustments; O-016..O-018 added | Product owner (decision), Claude Code (record) | docs 06, uiux/*, ADR-0009 (superseded), ADR-0010, CLAUDE.md, AGENTS.md, README, design/mockups |
| 2026-10-08 | AG-01..AG-12 recorded as D-009..D-020; P-001..P-004 promoted; DD-01..DD-08 derived; O-011..O-015 added from audit | Product owner (decision), Claude Code (record) | docs 02, 04, 05, 06, 09, 14, 15–21, ADR-0001..0009 |

## Change control
For every changed decision: record date, owner, rationale, affected modules, migrations, API/UI changes, acceptance tests, status and approval. Do not overwrite prior rationale silently.

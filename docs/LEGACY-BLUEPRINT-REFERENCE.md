# EORA Suite — Business Flow & UI/UX Design Blueprint

> **Status:** Draft product requirements and design brief for Claude Code. Not a validated implementation specification.  
> **Brand:** EORA Suite — Enterprise Operations & Resource Applications  
> **Positioning:** Manufacturing Operations Management Suite  
> **Tagline:** Plan. Produce. Trace. Deliver.  
> **Scope exclusion:** Finance, Accounting, HR/Payroll are **not** part of the planned product.

## 1. Instructions for Claude Code

Act as a senior manufacturing ERP business analyst, enterprise UX architect, product designer, security architect, and full-stack technical lead. **First audit this blueprint for gaps, contradictions, and hidden assumptions; then propose a coherent UX architecture and at least three distinct mockup directions.** Do not jump directly to coding. Do not claim SAP/Oracle feature parity. Reference SAP Fiori and Oracle Fusion SCM design principles conceptually, without copying their proprietary screens, assets, branding, or layouts. Avoid generic AI-generated dashboard aesthetics, excessive gradients, decorative cards, unnecessary charts, and dense modal chains. Favor pragmatic workflows and information hierarchy.

**Deliverables in order:** (1) requirements gap analysis and unresolved questions, (2) corrected business process map and transaction lifecycle, (3) information architecture and role-aware navigation, (4) three visual design concepts with rationale and tradeoffs, (5) high-fidelity mockups/prototypes for core screens, (6) design system and responsive specifications, (7) screen-to-permission matrix, (8) proposed implementation architecture, (9) phased MVP/V1/V2 plan. Request explicit approval of the chosen mockup before implementing production UI. If a repository is supplied, inspect existing code and constraints first; do not overwrite unrelated work.

## 2. Product objective

Provide end-to-end monitoring of customer demand, customer PO, forecast, kanban/delivery releases, production planning, machine/dies capacity, raw-material receiving and issue, substitution, actual production, QC, finished goods, warehouse transfers, delivery, stock opname, and reconciliation. Every quantity must be explainable by origin, lot, location, status, transaction, and destination. Support multiple tenant companies with strict data isolation, and configurable operational policies.

## 3. Logical module map

| Module | Core capabilities |
|---|---|
| EORA Core | Tenant/company/plant management, identity, MFA, RBAC + data scopes, master data, approvals, audit trail, notification, integration settings, data correction center |
| EORA Demand | Customer master, Customer PO, internal sales order / order lines, forecast, blanket order, kanban, delivery schedule/releases, revision, demand consolidation, fulfillment |
| EORA Planning | MPS, net requirements, MRP-lite, capacity/finite scheduling as later phase, machine/shift/dies allocation, production orders, shortage alerts |
| EORA Materials | Material receipt, lot/heat tracking, material inventory, reservation, issue, return, scrap, substitution, stock availability |
| EORA Production | Work order lifecycle, routing/operation, runs by machine/shift/lot, production reporting, OK/NG, setup/dandori loss, downtime, material consumption and reconciliation |
| EORA Quality | Incoming/in-process/final inspection, sampling policy, pending inspection, accepted/rejected, hold, rework, reinspection, release, NCR |
| EORA Inventory | Unified stock ledger, stock by ownership/location/lot/status, stock opname, cycle count, adjustment, monthly closing/opening balance |
| EORA Warehouse | Bin/location, handling unit/polybox/pallet, FG receiving, putaway, transfers, picking, staging, packing |
| EORA Delivery | Delivery order, shipment, customer release, delivery actual, outstanding demand, optional true customer consignment |
| EORA Maintenance | Machine and dies/tooling, PM/CM, stroke counter, breakdown, asset availability |
| EORA Traceability | Forward/backward lot genealogy, material substitution history, quantity and stock reconciliation, variance investigation |
| EORA Analytics | Role-based control tower, cross-module KPI, exception queues, drill-down, exports |

**Do not add Finance, Accounting, HR or Payroll modules.** Procurement/supplier PO is a candidate adjacent module, distinct from Customer PO, to be prioritized after requirements discovery.

## 4. End-to-end business process

1. Customer issues PO, forecast, blanket PO, kanban or delivery release. Preserve source document and revision; never blindly sum overlapping forecast and firm demand.
2. Demand consolidation classifies firm versus forecast, maps customer part to internal part, accounts for shipped and outstanding quantities.
3. Planning calculates FG available, reservations, pending QC (not available), shortages, BOM requirements, machine capacity, dies availability, shift/calendar, setup time, and planned work orders.
4. Materials receives RM with supplier, lot/heat, UOM, inspection/hold status and storage location. Reserve stock per WO, then issue actual material lot to production.
5. If primary BOM material is unavailable, only allow approved substitutes with specification/customer constraints and engineering/quality authorization. Preserve **planned BOM material** separately from **actual material consumed**; support partial substitution and multiple lots.
6. Production runs WO by shift/machine/dies/operation and reports produced good, NG, setup scrap, process scrap, downtime, material returned, and unaccounted variance. Material issued is **not automatically material consumed**.
7. Output enters Pending Quality Inspection until disposition; QC may accept, reject, hold, rework, reinspect, or scrap according to rules. Do not double count production output and FG.
8. Accepted output is received into FG warehouse with lot, container/polybox, quantity, location, and QC release reference.
9. Warehouse picks/packs/stages FG; Delivery posts actual shipment and updates appropriate customer schedule/PO outstanding. Customer consignment is only used if physical custody/ownership rules genuinely qualify.
10. Stock opname or cycle count takes a cut-off/snapshot, counts by lot/location/status, reviews variance, approves adjustment, posts ledger entries, closes period, and carries forward final closing as next opening. Never overwrite historic movements.
11. Traceability, dashboard and alerts consume the shared transactional truth across all stages.

## 5. Required business rules

- Every posted inventory movement is uniquely identified, atomic, idempotent and auditable; protect against duplicate submission and race conditions.
- Record tenant, company, plant, warehouse/bin, item, UOM, lot, status, ownership, quantity, actor, timestamp, source and destination, reference document, and reason where relevant.
- Separate physical on-hand, reserved, QC hold, pending inspection, allocated, unrestricted available, consigned, and damaged/scrap stock.
- Support kg/coil/sheet/pcs and conversions with controlled master data; material mass balance is not equivalent to finished-piece counts without BOM yield, unit conversion and loss assumptions.
- Preserve BOM/routing revision snapshots and customer-specific part/specification approvals at WO release.
- One WO may span multiple runs, shifts, machines, material lots and finished lots; trace actual genealogy.
- Production output, QC inspection, QC acceptance, FG receipt and delivery are different events. Each event has its own reconciliation.
- Posted documents are not casually edited or hard-deleted. Corrections use void/reversal, compensating transactions, approval and impact analysis. Keep original and corrected audit records.
- Locked accounting/inventory periods cannot be silently reopened or backdated; use approved correction policy.
- Physical count adjustments require reason, supporting evidence, approval and maker-checker separation where appropriate.
- Forecast, customer PO, delivery schedule and kanban may represent overlapping demand; use explicit linkage and consumption rules.
- Reject unauthorized substitutions, stock from unapproved/held lots, delivery of unreleased FG, negative inventory (unless explicit policy), and actions outside plant/warehouse scopes.
- Consignment stock is a distinct ownership/custody business model, **not** a synonym for Pending QC or a generic holding warehouse.

## 6. Identity, security and permissions

### Roles (configurable templates)
- EORA Platform Admin: manage SaaS infrastructure/tenant provisioning, not unrestricted customer operational data.
- Company Super Admin: company-wide administration and controlled correction authority within tenant.
- Company Admin, PPIC Planner, Warehouse Operator/Supervisor, Production Operator/Leader, Quality Inspector/Supervisor, Maintenance, Delivery, Plant Manager, Auditor.

### Permission model
- Use fine-grained permission strings such as `production.wo.create`, `production.wo.release`, `materials.issue.post`, `quality.release.approve`, `inventory.adjustment.request`, `inventory.adjustment.approve`, `inventory.adjustment.post`, `demand.po.revise`, `security.roles.manage`, `audit.view`.
- Authorize on the server for every API action and object, including tenant/plant/warehouse scope; hidden navigation is **not** security.
- Enforce least privilege, deny-by-default, optional MFA/SSO, step-up auth for high-risk actions, secure session management, rate limiting, logging, encryption, backup and recovery testing.
- Company Super Admin can initiate or authorize controlled correction but cannot erase accountability or bypass tenant isolation. Maker-checker applies to sensitive corrections.
- Data Correction Center: request, justification, impact preview, evidence, approval, reversal/repost, audit record and notifications.

## 7. UI/UX direction

**Recommended aesthetic:** restrained, premium industrial SaaS; modern enterprise productivity rather than flashy consumer dashboard. Strong typography, calm neutral surfaces, one consistent primary accent, semantic status colors, accessible contrast, dense but readable tables, purposeful whitespace, clear document lifecycle.

### Layout
- Desktop: collapsible left navigation grouped by business domain, top bar with company/plant selector, global search, date/shift context, notifications, account.
- Content: page title + contextual breadcrumbs + status and primary action; tabs for related document views; optional detail side panel; full-page workspaces for complex tasks.
- Responsive: desktop-first for PPIC/admin; tablet optimized for production/warehouse; phone-friendly scan and quick-entry workflows. Do not shrink giant tables into unusable mobile views.
- Role-based home dashboard and navigation. Global search across PO/WO/material lot/FG lot/delivery/stock movement with permission filtering.

### UI archetypes
1. **Operations Control Tower:** concise KPI strip, alerts prioritized by impact, production vs plan, demand coverage, QC queue, material shortage, FG coverage, delivery risk, variance; click to drill down into source documents.
2. **Master Data / Transaction List:** filterable, saved views, column personalization, sorting, grouping, bulk operations only where safe, exports with permissions, clear statuses and row actions.
3. **Transaction Editor:** structured form with document header, reference, line items, stock availability, lot picker, substitution/approval status, validation, save draft vs post, confirmation and audit context.
4. **Planning Board:** timeline/Gantt by machine/shift, demand-to-plan links, material and dies constraints, conflict highlighting, drag/drop only with validation and rollback.
5. **Production Workbench:** large-target quick-entry, shift and machine context, barcode/QR scan, operator-friendly output/NG/downtime inputs, intermittent-connectivity considerations.
6. **Quality Workbench:** inspection queue, sampling plans, accepted/rejected/hold actions, defect codes, attachments, reinspection genealogy.
7. **Inventory/Opname Workbench:** book-vs-physical side-by-side, blind count option, variance review, recount, approval, cut-off/period state.
8. **Traceability Explorer:** forward/backward interactive lot graph with filters and a readable chronological transaction ledger alternative.
9. **Correction Center:** original transaction, proposed correction, affected downstream movements, approval, audit history; no unrestricted delete shortcut.
10. **Administration & Security:** users, roles, permissions matrix, data scope, access history, session/security alerts, approval policy.

### Interaction standards
- Keyboard-accessible controls, clear focus states, loading/empty/error states, optimistic UI only when safe, undo for non-posted UI actions, server validation for posting.
- Human-readable errors: show what failed, why, affected stock/WO and next action.
- Avoid generic charts where a queue/table/exception list answers the user faster. Always define KPI formulas and denominator, date basis, shift timezone, refresh latency and drill-down path.
- Clearly distinguish Draft, Released, In Progress, Pending QC, Hold, Accepted, Rejected, Posted, Closed, Voided.
- Tables must handle thousands of rows through server-side filtering/pagination or virtualization.
- Use consistent Indonesian/English terminology strategy and allow localization; default timezone Asia/Jakarta for Indonesian deployments.

## 8. Mockup deliverables requested from Claude Code

**First propose THREE differentiated visual concepts** (e.g. `Industrial Control Tower`, `Fiori-inspired Productivity Workspace`, `Minimal Data-First SaaS`). For each: rationale, navigation, color/type/spacing tokens, density, strengths/weaknesses, accessibility and suitability for factory operations. Recommend one with reason; let product owner select.

Then mock up **at least 10 key screens** with realistic synthetic data clearly labeled as sample:
1. Login + company/plant selection + MFA state
2. Executive Operations Control Tower
3. PPIC Demand & Delivery Risk dashboard
4. Customer PO/Forecast/Kanban & schedule list/detail
5. Production planning calendar/Gantt with capacity conflicts
6. Material Issue with approved substitute and lot picker
7. WO detail + production run reporting and reconciliation
8. Quality inspection queue and disposition
9. FG inventory and warehouse handling units
10. Stock Opname + variance and approval
11. Traceability genealogy graph + ledger
12. Company Admin RBAC matrix + Data Correction Center

For each mockup provide user role, purpose, primary tasks, data fields, state transitions, edge/error states, responsive behavior, and backend permission requirements. Mockups should show meaningful sample values, not lorem ipsum. Provide desktop screens plus mobile/tablet variants for production, QC and warehouse.

## 9. Suggested design system

- Typography: professional sans-serif with legible numerals, tabular numerals for quantities.
- Spacing: consistent 4/8px-based rhythm; compact/comfortable density toggle.
- Color: neutral base, single primary action color; semantic success/warning/danger; color must not be sole carrier of meaning.
- Components: AppShell, SidebarNav, ContextBar, MetricCard, StatusBadge, DataTable, FilterBar, SavedView, DocumentHeader, LineItemEditor, QuantityInput, UOMSelector, LotPicker, ApprovalTimeline, AuditHistory, ExceptionBanner, ConfirmationDialog, ScanInput, Gantt, GenealogyGraph.
- Accessibility: target WCAG 2.2 AA where practical, full keyboard navigation, labeled fields, visible focus, accessible charts/tables.

## 10. Dashboard specifications

### Executive
Demand, plan, actual, accepted, FG available, delivered, NG rate, delivery fulfillment, OEE (only if reliable data), pending QC, material shortage, stock variance, unaccounted loss. Filter by period, company, plant, customer, part, shift; permission aware.

### PPIC
Tomorrow/weekly demand, firm vs forecast, FG coverage, projected shortage, machine/dies capacity, material reservation, WO status, late delivery risk.

### Production
WO target vs actual, good/NG/scrap, output by shift, downtime, material issued vs consumed, run-level reconciliation.

### Quality
Pending inspection aging, acceptance/rejection, defect Pareto, hold/rework, release queue, lot genealogy.

### Warehouse/Inventory
RM/FG by status/location, reservations, stock movements, cycle count, pending stock adjustments, FG staging.

### Maintenance
Asset availability, breakdown, preventive schedule, stroke thresholds, downtime by machine/dies.

### Security/Admin
Pending corrections, high-risk approvals, failed logins, privilege changes, suspicious events, audit exports.

## 11. Acceptance criteria for UX proposal

- Users can move from demand to WO, material, production lot, QC, FG and delivery without losing document lineage.
- Posted transactions show immutable history and controlled correction flow.
- A user without authorization cannot see restricted data through UI, search, export, or API.
- Stock quantities are consistently reconciled across modules and dashboard KPIs.
- Forecast/PO/kanban overlap does not double count demand.
- UI surfaces pending decisions, shortages and discrepancies proactively.
- Common operator tasks minimize clicks and prevent invalid quantity/lot/UOM entries.
- Screens include empty/loading/error/hold/partial completion and period-locked states.
- Mockups are original, enterprise-ready, not copies of SAP/Oracle screens.

## 12. Open questions for discovery

1. Is production discrete stamping only initially, or should multi-operation routing, welding, fabrication and assembly be supported from day one?
2. Is final QC 100% or sample-based? Can a lot be released partially?
3. When does issued material become consumed, and how are coils/skeleton scrap/returns measured?
4. How are customer PO, blanket orders, forecast, kanban and delivery release related by each customer?
5. Are material substitutes globally approved, customer-specific, or WO-specific?
6. Can one WO produce multiple FG lots or use multiple machines/dies and material lots?
7. How are period cut-off, late postings, backdated corrections and stock opname handled?
8. Does customer consignment exist contractually, or is 'gudang konsimen' merely a local name?
9. Is Procurement in scope for V1, or will RM receipts reference external supplier POs?
10. What are the target user counts, plants, transaction volume, barcode hardware, offline needs and integration constraints?

## 13. Development guidance (only after mockup approval)

Recommend a modern, stable, maintainable and secure stack suited to a commercial multi-tenant SaaS. Prefer a modular monolith initially, well-defined domain boundaries, PostgreSQL transactional integrity, backend-enforced RBAC/data scope, API contracts, migrations, automated tests, observability, background jobs and robust inventory ledger design. Evaluate Laravel + React/Next.js + TypeScript versus a TypeScript-centric backend objectively. Do not start with microservices unless scale/organization genuinely requires them. Distinguish domain events from posted stock movements. Include concurrency, idempotency, security and performance tests. Do not fabricate business metrics or claim a production-ready system from mockups alone.

---

**Immediate instruction to Claude Code:** Review this specification, identify contradictions or missing rules, and present **three high-quality original UI/UX mockup directions** plus an information architecture and screen map. **Wait for selection/approval before implementation.**

---

## 14. Mobile PWA — Unified Enterprise Experience (mandatory)

EORA Suite MUST offer a responsive desktop web application and a **mobile-first installable Progressive Web App (PWA)** sharing the same domain backend, authorization, tenant isolation, audit trail, data definitions, and transaction integrity. Do not simply shrink desktop tables onto a phone. Design role-specific mobile workflows for operators, QC, warehouse, maintenance, delivery, supervisors, and managers.

### 14.1 PWA technical requirements

- Installable via valid web app manifest (name, icons, display mode, start URL, theme/background colors) and HTTPS; service worker for controlled caching and push event handling.
- Adaptive layouts: desktop sidebar and data-dense tables; mobile bottom navigation, task-first home, contextual actions, compact KPI cards, searchable lists, progressive disclosure, accessible touch targets, safe-area support, and clear loading/error states.
- Design mobile transaction flows for scanning lot/WO/handling-unit QR/barcodes, material receipt/issue/return, production reporting, QC inspections, stock counting, picking/packing, delivery confirmation, maintenance incidents, and approval decisions. Camera access requires user permission; provide manual entry fallback.
- Mobile dashboard widgets and shortcuts must follow tenant/plant/warehouse/department and action-level RBAC. Hiding a UI element is not an authorization control: enforce access on every API and deep-linked destination.
- Connectivity: display online/offline/sync state. Cache only approved, non-sensitive static assets and explicitly permitted read data. Offline transactional drafts are an optional, separately designed feature; never imply a transaction is posted while offline. If enabled, queue encrypted/appropriately protected drafts with explicit reconciliation, idempotency, expiry, conflict resolution and server-side revalidation on reconnect. No blind replay of stock movements, QC releases, approvals or period closings.
- Mobile performance, low-bandwidth use, accessibility (keyboard/screen reader/contrast), localization, Asia/Jakarta timestamps, and security testing are required.
- iOS/iPadOS web push requires supported OS/browser conditions and an installed Home Screen web app; desktop and Android support also vary. Provide clear enable-notifications onboarding and graceful in-app-only fallback. Never promise universal push delivery or silent background sync.

### 14.2 Mobile information architecture and proposed screens

1. Mobile Home / My Shift: role-specific KPIs, assigned tasks, critical alerts, shortcuts, current plant and shift.
2. My Tasks: inspections, WO execution, stock counts, pending approvals, assigned maintenance jobs.
3. Scan Center: QR/barcode scanning with manual fallback, preview and permission checks.
4. Transaction Wizard: step-by-step data entry, validation, draft/review/submit, confirmation and document reference.
5. Notification Inbox: bell badge, tabs All / Unread / Action Required, filter by module, priority, date, read/unread, archive and preferences.
6. Notification Detail: contextual event, source document, timestamp, actor, severity, actionable deep link.
7. Approvals: pending requests, impact preview, reason/evidence, approve/reject with separation of duties.
8. Profile & Device Settings: active tenant/plant, sessions, push permission, device subscription management and notification preferences.

Ask Claude Code to produce **three original mobile PWA mockup directions** coordinated with the desktop design, plus a desktop bell notification panel and full notification center. Include wireframes for iPhone-size and Android-size viewports, tablet, desktop; light/dark variants as appropriate; empty, loading, offline, permission denied, expired session, error, unread, and action-completed states. Evaluate navigation and usability for gloved or fast-paced manufacturing-floor tasks without assuming gloves work with all touchscreens.

## 15. Cross-Module Notification & Event Engine (mandatory)

### 15.1 Notification channels

- **In-app Notification Center** (bell): persistent server-side inbox, unread count, read/unread state, user-specific filters, module grouping, actionable destination.
- **Web Push**: optional permission-based device/browser notifications via service worker and Web Push subscription, backed by a durable job queue. Push is a delivery channel, not the system of record; the in-app inbox remains authoritative even if push fails, expires or is disabled.
- **Realtime in-app updates**: use authenticated WebSocket/SSE or suitable polling for bell badge and task changes; ensure server authorization and reconnect handling.
- Optional future email or external messaging connectors, configurable per tenant and event.

### 15.2 Business events and recipients

| Domain event | Typical recipients | Destination / action |
| --- | --- | --- |
| Customer PO created/revised/cancelled | PPIC, Demand owner | Demand > PO detail / review impact |
| Forecast or Kanban release changed | PPIC | Demand > schedule/reconciliation |
| WO released/rescheduled | Assigned production team | Production > WO/run |
| Material shortage detected | PPIC, Materials planner | Materials > shortage / allocation |
| Material substitution approval requested | Authorized approver, Quality/Engineering as policy dictates | Approval > substitution request |
| Material issue/return posted | Relevant WO owner, inventory controller as configured | Materials > transaction detail |
| Production output submitted / QC pending | Assigned Quality team | Quality > inspection queue / specific lot |
| QC lot held/rejected/released | Production, Warehouse, PPIC according to event | Quality > disposition / FG availability |
| Stock opname variance exceeds threshold | Inventory controller, approver | Inventory > count/variance investigation |
| Data correction/reversal requested | Authorized independent approver | Core > Correction Center |
| Shipment delayed / dispatched | PPIC, Delivery owner | Delivery > shipment |
| Machine breakdown / dies stroke threshold reached | Maintenance, PPIC | Maintenance > equipment / work order |
| Approval completed or rejected | Request creator | Original request / decision detail |
| Security-sensitive event | Tenant security administrator | Core > Security Events |

Recipients must be resolved from **role + permission + tenant + plant + resource assignment + user preferences**, never broadcast cross-tenant. A user must be reauthorized at click time; if their access was revoked, show a safe no-access state, not the underlying record.

### 15.3 Notification data contract and lifecycle

Each notification should carry: immutable notification ID, tenant ID, event ID and type, originating module, source entity type/ID, recipient user ID, title/body (minimal sensitive content), severity, priority, created/occurred timestamps, action/deep-link route and parameters, read timestamp, expiry if relevant, correlation ID, deduplication key and delivery-channel statuses. Maintain event schema versioning. Store a per-recipient inbox record; store push subscriptions per user/device with endpoint/key lifecycle, revoke on logout/device removal and remove invalid subscriptions.

Suggested flow: **domain transaction commits → transactional outbox event → worker evaluates notification policies and recipients → persists inbox records → broadcasts authorized realtime updates → queues Web Push to opted-in devices → click routes to target module and checks permissions**. Use at-least-once event processing with deduplication/idempotency, retry/backoff, dead-letter handling and observability; do not send notifications before the originating transaction is committed.

Notification preferences: per-module/category opt-in, critical vs informational severity, quiet hours where allowed, per-device push enablement, bundling/digest, anti-spam/rate limiting, escalation for unresolved actionable items, and retention policy. Avoid exposing confidential PO quantities, customer names, security events or personal data on lock screens by default; use generic push copy when necessary. Push payloads must never grant authorization.

### 15.4 Deep-linking and bell UX

- Bell icon in desktop top bar and mobile header/bottom-navigation inbox with accessible unread badge; distinguish unread messages from outstanding action-required tasks.
- Clicking a notification should open the exact authorized entity: e.g., `/quality/inspections/{inspectionId}` or `/core/corrections/{requestId}` (illustrative routes, not finalized contracts).
- Support authenticated routing across modules, restore selected tenant/plant context only if permitted, handle deleted/archived records and stale notifications gracefully, and preserve browser back behavior.
- Notifications should clearly show what happened, why it matters, which module generated it, when it happened and the next available action. Mark-as-read is distinct from completing the business task.
- Approvals and stock-affecting actions must open a verified detail/review screen, not execute directly from a push click. Require fresh authorization and step-up MFA for high-risk actions as policy dictates.

### 15.5 Acceptance criteria / testing

1. A posted production report generates exactly one logical QC-pending notification per intended recipient even if event delivery retries; bell and push reference the same record.
2. User without Quality access cannot open QC inspection from a copied push link; API returns safe authorization failure.
3. User A at Tenant A never receives or reads Tenant B notifications, even through guessed IDs or search.
4. When push permission is denied, notifications still appear in the in-app bell.
5. Offline devices do not show uncommitted transactions as completed; on reconnect, inbox syncs without duplicate entries.
6. Read state syncs across authorized devices; read does not imply task completion.
7. Revisions, reversals and cancelled approvals update or supersede actionable notifications so stale buttons cannot perform invalid actions.
8. iOS Home Screen installation and push prerequisites are explained in-app; unsupported environments have graceful fallback.
9. Notification preferences, quiet hours, grouping and severity routing are tested, including high-volume event bursts.
10. Every notification click is revalidated against current user session, permission and tenant/plant scope.

## 16. Additional Claude Code deliverables before implementation

Produce: (A) desktop and mobile information architecture; (B) three coordinated design directions with strengths/weaknesses; (C) desktop + PWA mockups and component inventory; (D) notification bell/inbox, push-permission onboarding and deep-link interaction flows; (E) cross-module event catalog and recipient permission matrix; (F) PWA/browser support and offline strategy; (G) testable acceptance criteria; (H) open questions and recommended MVP/V2 split. **Do not implement until the user approves the chosen mockup direction and the unresolved business rules.**

---

## 17. EORA Document & Data Exchange Engine — Excel Import, Excel/PDF Export, Print & Branding

### 17.1 Objective and scope
Provide a **shared Core service**, reusable by all authorized modules, for `.xlsx` export, `.pdf` export, `.xlsx` import, standardized print-ready layouts, templates, report generation, and document traceability. No module should independently implement inconsistent watermark, validation, or access-control rules. Distinguish business documents (WO, QC release, delivery note, stock adjustment) from analytical reports and machine-readable imports.

### 17.2 Standard document identity and timestamp
- Report/document header: tenant company name/logo (where configured), plant/site, report title, document number, business status, revision, filter/period, and optional confidentiality label.
- Footer, PDF and print: `Generated by EORA Suite | Generated on 23/12/2026 09:43:20 WIB | Document ID: DOC-... | Page X of Y` (example only). When genuinely printed via a print-generation workflow, `Printed on ...` is appropriate; for downloads use `Generated on`/`Exported on`, never falsely assert physical printing.
- Use server-generated timestamps, store UTC internally, render in authorized tenant timezone (default `Asia/Jakarta`) with explicit timezone indicator. Record exporter identity in audit logs; optionally display name/username in document according to tenant privacy policy.
- Watermark strategy: subtle **EORA Suite** brand in header/footer, optional low-opacity diagonal watermark for `DRAFT`, `VOID`, `UNCONTROLLED COPY` or `CONFIDENTIAL`; do not obscure tables, barcodes, QR codes or signatures. Company branding remains prominent and configurable; EORA attribution is consistent and discreet.
- Include `Document ID`, `Generated at`, document revision, source snapshot/version and optional verification QR/link. A QR is only useful when backed by an authenticated verification endpoint and cannot expose confidential data publicly.

### 17.3 PDF/print specification
- Page sizes A4/A3, portrait/landscape, print CSS and page-break handling; repeated table headers, running page numbers, controlled margins, legible monochrome printing and accessible labels.
- Consistent templates for customer PO report, forecast, WO, material receipt/issue/return, production report, QC inspection/release, stock card, stock opname/variance, FG receipt, picking list, packing list, delivery note, maintenance history and traceability report.
- Print preview before generation, print-specific permission, report parameters, pagination, configurable company signature/approval blocks; clearly indicate draft versus approved copies.
- Printed/exported snapshot must not silently mutate if source transactions change; subsequent exports use new generation metadata and appropriate revision/status.

### 17.4 Excel export specification
- True `.xlsx` workbook, readable data types, date/time/quantity/UOM formatting, frozen headers, filters, sensible column widths, report title/filters, document metadata and optional summary sheet.
- Place EORA attribution and generation timestamp in a visible report header and **Excel print header/footer**. Note: spreadsheet files do not reliably support arbitrary translucent page watermarks across all viewers; do not overlay huge watermarks over editable data.
- Configure print area, page orientation, repeat header rows, fit-to-width and page footer where supported. Protect formula/template cells where appropriate but never treat worksheet protection as a security boundary.
- Exports honor the current **server-side** user permissions, tenant/plant/data scope, filters and approved data visibility. Sensitive columns must be excluded or masked by policy, not just hidden in Excel.
- Large exports run as asynchronous jobs with progress, cancellation, expiration and secured download links. Apply CSV/Excel formula injection protection to untrusted string cells; avoid accidental executable hyperlinks/macros. Prefer `.xlsx` without macros.

### 17.5 Excel import specification
- Only allow `.xlsx` through an authorized, module-specific import permission. Offer downloadable **versioned templates** with documented required columns, data types, examples, controlled vocabularies and per-row business identifiers.
- Import workflow: **Download template → Upload → Parse in isolated staging → Validate schema → Validate references/business rules/tenant scope → Preview proposed changes → Show row-level errors/warnings → Confirm → Required approval → Atomic or chunked idempotent posting → Audit + result report**.
- Validate file size, workbook structure, sheet name, template version, required columns, allowed MIME/signature, formula handling, duplicate rows/keys, UOM, decimals, dates, lot numbers, status transitions, foreign keys, stock availability and effective BOM/revisions. Treat every spreadsheet cell as untrusted input; never evaluate uploaded formulas or macros.
- Specify per-template operation (`create`, `update draft`, `upsert master`, or controlled correction request). **Never bulk overwrite posted inventory ledger, QC release, delivery or closed-period transactions.** Such changes must go through reversal/adjustment/approval workflows.
- Preview includes accepted/rejected/duplicate row counts, before/after diff, conflict resolution and exact impacted entities. Ensure import jobs are idempotent on retry and concurrency-safe. Support partial import only when explicit and safe; default to all-or-nothing for tightly linked documents.
- Store import job ID, source file hash, uploader, timestamp, template version, row-level outcomes, approval, posted document IDs, and retention/deletion policy. Never echo another tenant's data in error messages.
- Imports should emit normal domain events after successful commit, so inventory, traceability, dashboard and bell/push notifications update consistently.

### 17.6 RBAC and audit permissions
Illustrative granular permissions: `reports.export.xlsx`, `reports.export.pdf`, `reports.print`, `reports.download`, `imports.template.download`, `imports.upload`, `imports.validate`, `imports.commit`, `imports.approve`, `documents.verify`, `documents.template.manage`. Evaluate per module, document type, action and tenant/plant/warehouse scope. Export/download/print are sensitive data-exfiltration surfaces; log requester, report type, scope, filters, timestamp, job ID and result. Download links must expire and recheck authorization.

### 17.7 UI/UX requirements for Claude Code
- Desktop: consistent table toolbar **Export ▾ (Excel/PDF)**, **Import Excel**, **Print**, **Download Template**; show only allowed actions. Report configuration dialog with columns, date range, plant, statuses, orientation, branding and preview.
- Mobile PWA: compact action sheet for export/print, mobile-safe file download/share, import where appropriate, progress/status and errors; prioritize scanner and fast transaction entry over bulk uploads on small screens.
- Design mockups for: (1) export dialog; (2) branded PDF preview; (3) Excel report/print view; (4) import template selection; (5) upload and validation; (6) row-level error table; (7) confirmation/approval; (8) job history/download center.
- Notify users through **bell notification**, with optional Web Push, when asynchronous exports/imports complete, fail or require approval; deep-link to authorized job details and secured result downloads.

### 17.8 Testable acceptance criteria
1. A Quality user without Delivery export permission cannot export Delivery data via UI, URL or API.
2. A user at Tenant A cannot access Tenant B's export file, import job, report parameters or signed download URL.
3. Excel and PDF exports of the same report apply the same server-side filters, access scope and snapshot cut-off.
4. PDF and print footer display correct EORA branding, timestamp/timezone, document ID and page number; draft/void documents are visibly distinguished.
5. Excel output is filterable, printable, has typed quantity/date cells and EORA footer without obstructive cell watermark.
6. An import containing invalid lot, unauthorized warehouse, duplicate ID or closed-period posting is rejected with row-specific reasons before commit.
7. Retrying a completed import does not duplicate material issue, QC inspection, FG receipt or shipment.
8. Imports affecting posted stock never directly edit immutable ledger entries; they use controlled transaction paths and approval.
9. Export and import jobs create audit entries; asynchronous completion creates one logical in-app notification and optional push.
10. Print layout remains readable in grayscale and at A4/A3 sizes, with repeated headers and sensible page breaks.

### 17.9 Claude Code assignment
Before implementation, review this section against the existing module workflows and RBAC. Recommend the most maintainable libraries/services for server-side PDF generation, `.xlsx` creation/parsing, queue/background jobs, storage, print styling, and secure downloads based on the actual chosen technology stack. Provide architecture, sample report templates, import error UX and mockups. Do not implement until the user approves the overall UI/UX direction and import/posting policies.

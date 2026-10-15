# EORA Navigation Architecture

**Status:** DECIDED (D-021, ADR-0010 + Amendment 1 / D-025, D-026) · **Canonical for:** application shell, primary/secondary sidebar, topbar priorities, mobile navigation sheet, navigation badges, navigation configuration, responsive navigation. Principles: [EORA-DESIGN-CONSTITUTION.md](EORA-DESIGN-CONSTITUTION.md). Prototype: `design/mockups/assets/eora-nav.config.js` + `eora-shell.js`.

## 1. Reference analysis (owner screenshot, 2026-10-08)
| Reference pattern | EORA adaptation |
|---|---|
| Coloured vertical icon rail, bell + profile at bottom | `EoraPrimarySidebar` on `#172554`, 13 modules, Administration pinned bottom. Bell and profile live in the **topbar** (owner spec §9) so they stay next to company/plant context. |
| White secondary panel with module title (`≡ INVENTORY`) and items with count pills | `EoraSecondarySidebar`: uppercase module title + collapse button; items with icon, label and **actionable** numeric badge only (§10 — the reference's total-record pills are not adopted); **grouped by business function** with collapsible group headers. |
| Active item: light background, dark count pill | Active: `#EFF6FF` + accent text + accent icon. |
| Breadcrumb `INVENTORY / PRODUCT` | `EoraBreadcrumb` in topbar, uppercase 12 px, last item current page. |
| `VIEW BY: All · In Stock · …` tabs | `EoraStatusTabs` with "View by" label and counts — mapped to approved stock dimensions (see EORA-PAGE-TEMPLATES.md §2). |
| Search + filter toggle + record count; actions right | `EoraEnterprisePage` toolbar: search, filter toggle, record count; Import, Export ▾, Print, Column settings. |
| Segmented filter (`Filter by │ Company ▾ │ … │ Apply`) | `EoraFilterBar` segmented variant + Reset + active-filter chips + Save as view. |
| Multi-status quantity cell (Incoming/Usable/…) | **Grouped quantity columns** (header group "Warehouse stock (base UOM)" → Available · Allocated · Hold · Blocked), disjoint buckets that sum to on-hand. The stacked mini-label cell from the reference is not used: it doubled row height (Amendment 1). |
| Location chip `SCS4 +2` | `LocationChip` with overflow count. |
| `Edit · ⋯` row actions | Primary row action (View) + overflow menu, permission-aware. |
| Items per page · Go to page · First/Prev/1 2 3/Next/Last | `EoraPagination` server-side, with go-to-page. |
Not adopted: pink/purple gradient rail, brand mark, yellow location chips, product naming.

## 2. Shell anatomy (desktop ≥ 1280 px)
```
┌────┬──────────────┬──────────────────────────────────────────────────────────────┐
│ E  │ ≡ PPIC / …   │ ◧  PPIC / PLANNING › CONTROL TOWER   [Search ⌘K] [Period] [Company·Plant] 🔔 RP │
│ ▣  │ Overview  ▾  ├──────────────────────────────────────────────────────────────┤
│ ▣● │  Control …   │  Page header · description · actions                         │
│ ▣  │  Planning …  │  View-by tabs / filter bar / content (EoraEnterprisePage)    │
│ …  │ Customer …▾  │                                                              │
│ ⚙  │  …           │                                                              │
└────┴──────────────┴──────────────────────────────────────────────────────────────┘
 64px     248px                         fluid workspace
```

## 3. Primary sidebar (`EoraPrimarySidebar`)
Modules in order: Dashboard · Demand · PPIC / Planning · Materials · Production · Quality · Inventory · Warehouse · Delivery · Maintenance · Traceability · Analytics · Administration (pinned bottom).
- 44 × 44 icon buttons, Lucide 20 px, tooltip on hover **and** focus, accessible name includes attention count.
- Active module: accent fill. Indicator: **numeric badge** = sum of distinct actionable badge sources inside the module (§10). No badge when nothing needs the user's action. ~~8 px amber dot~~ (superseded by D-025).
- Keyboard: ↑/↓ move between modules, → moves into the secondary menu, Enter/Space opens module.
- RBAC: modules without `permission` for the user are not rendered (server-filtered config); hiding is never the security control.

## 4. Secondary sidebar (`EoraSecondarySidebar`)
- Header: uppercase module title + collapse button. Collapsed state persists per user.
- Groups: collapsible headers (`aria-expanded`), items: icon + label + optional count pill / decision tag.
- Route-aware: active item derived from current route, not page code.
- Badges are server-provided **actionable** counts only (§10). Total record counts (e.g. "214 lots") belong in the page's record count, never in navigation.
- Items gated by `permission`, `release` (R1/R2 shown; V2 hidden behind feature flag) and `decision` (items depending on an OPEN decision show a tag in prototypes; in production they stay hidden until decided).

## 5. Topbar (`EoraTopbar`)
Left → right: (mobile) labelled **Menu** button · secondary-sidebar toggle · breadcrumb · global search · optional page context (period = production date + shift; warehouse) · **workspace switcher (company + plant)** · notification bell · profile menu. Bell and profile stay at the right end (O-018 approved).

**Responsive priority (D-026)** — items never hidden: current-page crumb, bell, profile, access to company/plant.
| Width | Search | Breadcrumb | Page context | Company / plant | Profile |
|---|---|---|---|---|---|
| ≥ 1600 | full input (260 px) with `Ctrl K` hint | full path | label + value | two-line switcher | avatar + name/role (≥ 1680) |
| 1440–1599 | compact input (~200 px) | first › … › current (middle in tooltip) | label + value | two-line switcher | avatar |
| 1200–1439 | compact input if the page has no topbar context; otherwise icon | first › … › current | label + value | two-line switcher | avatar |
| 1024–1199 | icon → search dialog | first › … › current | label + value | two-line switcher | avatar |
| 768–1023 | icon | first › … › current | icon only (value in accessible name) | icon only | avatar |
| < 768 | icon | current page only | inside overflow (building icon) | inside overflow | avatar |
- Search strategy per **D-028** (supersedes the v2.1 rule "icon below 1600 px"). Entities: Customer PO, Sales Order, Work Order, Part Number, Material, Material Lot, Production Run, Inspection Lot, Delivery Order; debounced input (≈250 ms), loading/empty/error states.
- **Command search** (`Ctrl/⌘ K`): modal dialog, combobox + listbox, grouped results (work orders, lots, POs, cases, pages), ↑/↓/Enter/Esc, results limited to records the user may view in the active tenant; focus returns to the trigger.
- **Workspace switcher**: popover with company and plant selects (dependent), only assigned scopes listed; switching reloads data in the new scope and is audited.
- **Page context** appears only where the page declares it (`data-context` / page config).
- **Notification Center**: bell badge = number of **action-required** notifications (D-025); accessible name states action-required and unread counts. Panel tabs *Action required* (default) / *All* / *Unread*, grouped Today / Earlier, severity icon, Action-required vs Information label, deep link, per-item mark-as-read, Mark all as read, Notification settings. Full-screen dialog on phones with focus trap. Opening an item re-checks permission and tenant; mark-as-read ≠ task done (doc 07).
- Profile menu: profile & devices, notification preferences, sign out (prototype also has a role switcher to demo RBAC-filtered navigation).

## 6. Navigation configuration (single source)
Production file: `packages/features/navigation/eora-nav.config.ts`; the API returns the user-filtered tree (`GET /api/v1/me/navigation`) so the client never receives modules it cannot open.
```ts
type NavItem = {
  id: string; label: string; icon: LucideIconName; route: string;
  permission: string;            // e.g. 'planning.control_tower.view'
  release: 'R1' | 'R2' | 'V2';   // V2 hidden unless feature flag on
  decision?: `O-${number}`;      // depends on OPEN decision → hidden in production
  badge?: 'approvals' | 'qc' | 'variance' | 'tasks'; // actionable source only (§10); value computed server-side
};
type NavGroup = { label: string; items: NavItem[] };
type NavModule = { id: string; label: string; icon: LucideIconName; permission: string; pinBottom?: boolean; groups: NavGroup[] };
```
Rule: pages never declare menus; they declare `module`, `item` and breadcrumb only.

## 7. Module menus (V1 scope; R2 = V1 additions)
| Module | Groups → items |
|---|---|
| Dashboard | My Workspace → Overview, My Tasks, Approvals Inbox |
| Demand | Customer Orders → Customer Purchase Orders, PO Revisions · Releases & Forecast → Forecast (R2), Kanban / Delivery Releases (R2), Demand Netting (R2, bucket size O-011; counting level decided by D-034) · Master Data → Customers, Customer Part Mapping |
| PPIC / Planning | Overview → Control Tower, Planning Dashboard (R2) · Customer Demand → Demand & Fulfillment (CT-9, Phase 3), Customer Purchase Orders, Sales Orders (R1, D-030/D-035), Forecast (R2), Delivery Schedules (R1) · Production Planning → Master Production Schedule (R1, D-031/D-035), Work Orders, Capacity Planning (R2) · Material Control → Raw Material Availability, Coil / Sheet Conversion, Shortage & Delivery Risk (CT-8; renamed 2026-10-09 to match doc 19) · Production Monitoring → Work Order Progress, Daily Production Results, Pending Quality · Inventory Monitoring → Finished Goods Availability, Material at Production · Reconciliation → Material Consumption, Material Variance, Variance Investigation · Reports → Daily Finished Goods, Daily Delivery, Production Fulfillment (R2) |
| Materials | Receiving → RM Receipts, Incoming Release (R2 screen; V1 = manual release action on RM Receipt, D-062) · Issue & Return → Material Issue, Custody Acceptance (R2), Material Return, Substitution Requests (R2) · Lots → Material Lots, Remnants |
| Production | Execution → Production Runs, Run Reporting, Downtime (R2) · Output → Production Postings, Rework Orders (R2) · Reconciliation → Run Reconciliation, Measurements |
| Quality | Inspection → Pending QC Queue, Inspection Lots, Hold Lots (R2) · Master Data → Defect Codes (R2), Sampling Plans (R2) |
| Inventory | Stock → Stock Overview, Raw Material, Semi-Finished Material, Finished Goods, Material at Production · Ledger → Movement Ledger, Allocations · Control → Stock Count (R2), Adjustments, Reversals, Period Close (R2) |
| Warehouse | Inbound → FG Receipt, Putaway · Outbound → Handling Units, Picking, Packing (R2) |
| Delivery | Shipping → Delivery Orders, Shipments, Proof of Delivery (R2) · Monitoring → Outstanding Demand |
| Maintenance (R2) | Assets → Machines, Dies & Tooling (alias of the Master Data records, D-076 — no second master) · Work → Breakdowns, Preventive Maintenance |
| Traceability | Lot Trace → Backward Trace, Forward Trace, Genealogy Graph (R2) · Reconciliation → Reconciliation Cases |
| Analytics | Insights → Dashboards (R2), Reports · Data Exchange → Export Center, Import Jobs (R2) |
| Master Data (D-074, D-076) | Organization → Plants & Warehouses, Work Centers (sections: Work centers, Machines, Dies & tooling, Die parts) · Parts → Items, Units & Conversions · Partners → Customers, Customer Part Mapping, Suppliers · Engineering → Process Types, Routings, BOMs (M2 step 4), Conversion Data (step 5) · Data Exchange → Import (step 6); each item shown with its `master.*.view` permission |
| Administration | Organization → Company (Plants & Warehouses and Work Centers moved to Master Data, D-076; old links redirect) · Access → Users, Roles & Permissions, Approval Policies · System → Audit Log, Notification Settings (R2), Tenant Settings |
Label notes (O-017 approved, D-024): "Material Loss" → **Material Variance** for menus and general indicators; **Actual Material Loss** and **Physical Stock Discrepancy** remain as proven outcomes inside investigations and stock counts. "Finish Good" → **Finished Goods** everywhere. **Sales Orders** (D-030) and **Master Production Schedule** (D-031) are approved for V1 basic scope and placed in **R1** (D-035); Delivery Schedules follow the SO line demand identity (D-034). ~~Pending O-011 / O-014 tags~~ removed.

## 8. Responsive behaviour
| Width | Behaviour |
|---|---|
| ≥ 1280 | Primary + secondary sidebar docked; secondary collapsible |
| 1024–1279 | Primary docked; secondary opens as overlay from toggle or module click; workspace full width |
| 1024–1279 (overlay) | Secondary overlay has a backdrop; backdrop click, close button and Esc close it; focus returns to the trigger |
| 768–1023 | As above; topbar per §5 table |
| < 768 | Primary and secondary sidebars are not rendered. The labelled **Menu** button opens the mobile navigation sheet (§9). Floor tasks use the PWA bottom navigation (EORA-PAGE-TEMPLATES.md §5) |
Escape closes the topmost overlay (command search → popover → notifications → mobile sheet → secondary overlay); focus returns to the trigger.

## 9. Mobile navigation sheet (D-026)
Not a shrunken desktop sidebar: one left sheet (≤ 340 px) with a **drill-down**.
- Level 1 (default): current module's grouped menu with a "‹ All modules" back button. Level 2: module list with icon, label and actionable badge.
- Header: EORA mark + **Close** button (44 px). Items ≥ 48 px high, 15 px text.
- Behaviour: `role="dialog"` + `aria-modal="true"` + label "Navigation"; trigger has `aria-expanded`/`aria-controls`; focus moves to Close on open and returns to Menu on close; Tab is trapped inside; Esc, Close and backdrop tap close it; **body scroll is locked** while open; sheet content scrolls with `overscroll-behavior: contain`; closing automatically when the viewport grows to ≥ 768 px.

## 10. Navigation badge policy (D-025, canonical)
- Numeric badges appear **only** for items that need the current user's action. Allowed sources:
  | Source key | Meaning | Example placement |
  |---|---|---|
  | `approvals` | approval requests assigned to the user | Dashboard › Approvals Inbox, Inventory › Adjustments |
  | `qc` | pending QC inspections in the user's scope | Quality › Pending QC Queue |
  | `variance` | variance investigations owned by / assigned to the user or team | PPIC › Variance Investigation, Traceability › Reconciliation Cases |
  | `tasks` | tasks assigned to the user (custody confirmation, measurement, count) | Dashboard › My Tasks, Materials › Custody Acceptance |
  | `bell` | action-required notifications | Topbar bell |
- Never total record counts, never "new" counts of informational records.
- Counts are computed server-side per request with the same tenant, plant and permission scope as the destination list (opening the item must show the same number of actionable records). A user without permission for an item gets no badge for it.
- Primary-sidebar module badge = sum of distinct sources inside that module; the same record may legitimately count in two modules (e.g. a variance case in PPIC and Traceability), the bell is the unified view.
- Visual: red pill (`--color-danger`, white 11 px semibold, 4.83:1), `99+` cap, accessible name "…, N to action". Informational unread state uses the blue unread dot inside the Notification Center only.

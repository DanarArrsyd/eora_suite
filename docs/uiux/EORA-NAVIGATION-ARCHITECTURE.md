# EORA Navigation Architecture

**Status:** DECIDED (D-021, ADR-0010) · **Canonical for:** application shell, primary/secondary sidebar, topbar, navigation configuration, responsive navigation. Principles: [EORA-DESIGN-CONSTITUTION.md](EORA-DESIGN-CONSTITUTION.md). Prototype: `design/mockups/assets/eora-nav.config.js` + `eora-shell.js`.

## 1. Reference analysis (owner screenshot, 2026-10-08)
| Reference pattern | EORA adaptation |
|---|---|
| Coloured vertical icon rail, bell + profile at bottom | `EoraPrimarySidebar` on `#172554`, 13 modules, Administration pinned bottom. Bell and profile live in the **topbar** (owner spec §9) so they stay next to company/plant context. |
| White secondary panel with module title (`≡ INVENTORY`) and items with count pills | `EoraSecondarySidebar`: uppercase module title + collapse button; items with icon, label and server count pill; **grouped by business function** with collapsible group headers (reference is flat; owner requires groups). |
| Active item: light background, dark count pill | Active: `#EFF6FF` + accent text; count pill turns sidebar navy. Attention counts use warning pill. |
| Breadcrumb `INVENTORY / PRODUCT` | `EoraBreadcrumb` in topbar, uppercase 12 px, last item current page. |
| `VIEW BY: All · In Stock · …` tabs | `EoraStatusTabs` with "View by" label and counts — mapped to approved stock dimensions (see EORA-PAGE-TEMPLATES.md §2). |
| Search + filter toggle + record count; actions right | `EoraEnterprisePage` toolbar: search, filter toggle, record count; Import, Export ▾, Print, Column settings. |
| Segmented filter (`Filter by │ Company ▾ │ … │ Apply`) | `EoraFilterBar` segmented variant + Reset + active-filter chips + Save as view. |
| Multi-status quantity cell (Incoming/Usable/…) | `QuantityBreakdownCell` — disjoint buckets (Available, Allocated, Hold, Blocked) that sum to on-hand, base UOM shown. |
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
- Active module: accent fill. Attention indicator: 8 px amber dot (count is in the accessible name and secondary menu — avoids a wall of red numbers).
- Keyboard: ↑/↓ move between modules, → moves into the secondary menu, Enter/Space opens module.
- RBAC: modules without `permission` for the user are not rendered (server-filtered config); hiding is never the security control.

## 4. Secondary sidebar (`EoraSecondarySidebar`)
- Header: uppercase module title + collapse button. Collapsed state persists per user.
- Groups: collapsible headers (`aria-expanded`), items: icon + label + optional count pill / decision tag.
- Route-aware: active item derived from current route, not page code.
- Counts are server-provided **actionable** counts (e.g. pending, overdue). No vanity totals.
- Items gated by `permission`, `release` (R1/R2 shown; V2 hidden behind feature flag) and `decision` (items depending on an OPEN decision show a tag in prototypes; in production they stay hidden until decided).

## 5. Topbar (`EoraTopbar`)
Left → right: secondary-sidebar toggle · breadcrumb · global search (`Ctrl/⌘ K`, permission-aware results, hint for screen readers) · optional page context (period = production date + shift; warehouse) · **workspace switcher (company + plant)** · notification bell (unread badge) · profile menu.
- Context selectors appear only where the page uses them (`data-context` / page config). Company and plant are combined into one switcher to keep the bar usable at 1440 px.
- Notification Center: panel with tabs *All / Action required / Unread*, severity icon, timestamp, deep link; "Opening an item re-checks your access"; mark-as-read ≠ task done (doc 07).
- Profile menu: profile & devices, notification preferences, sign out (prototype also has a role switcher to demo RBAC-filtered navigation).

## 6. Navigation configuration (single source)
Production file: `packages/features/navigation/eora-nav.config.ts`; the API returns the user-filtered tree (`GET /api/v1/me/navigation`) so the client never receives modules it cannot open.
```ts
type NavItem = {
  id: string; label: string; icon: LucideIconName; route: string;
  permission: string;            // e.g. 'planning.control_tower.view'
  release: 'R1' | 'R2' | 'V2';   // V2 hidden unless feature flag on
  decision?: `O-${number}`;      // depends on OPEN decision → hidden in production
  count?: { source: string; attention?: boolean }; // server count query
};
type NavGroup = { label: string; items: NavItem[] };
type NavModule = { id: string; label: string; icon: LucideIconName; permission: string; pinBottom?: boolean; groups: NavGroup[] };
```
Rule: pages never declare menus; they declare `module`, `item` and breadcrumb only.

## 7. Module menus (V1 scope; R2 = V1 additions)
| Module | Groups → items |
|---|---|
| Dashboard | My Workspace → Overview, My Tasks, Approvals Inbox |
| Demand | Customer Orders → Customer Purchase Orders, PO Revisions · Releases & Forecast → Forecast (R2), Kanban / Delivery Releases (R2), Demand Netting (R2, O-011) · Master Data → Customers, Customer Part Mapping |
| PPIC / Planning | Overview → Control Tower, Planning Dashboard (R2) · Customer Demand → Customer Purchase Orders, Sales Orders (O-011), Forecast (R2), Delivery Schedules (R2) · Production Planning → Master Production Schedule (O-014), Work Orders, Capacity Planning (R2) · Material Control → Raw Material Availability, Coil / Sheet Conversion, Material Shortage · Production Monitoring → Work Order Progress, Daily Production Results, Pending Quality · Inventory Monitoring → Finished Goods Availability, Material at Production · Reconciliation → Material Consumption, Material Variance, Variance Investigation · Reports → Daily Finished Goods, Daily Delivery, Production Fulfillment (R2) |
| Materials | Receiving → RM Receipts, Incoming Release (R2, O-013) · Issue & Return → Material Issue, Custody Acceptance (R2), Material Return, Substitution Requests (R2) · Lots → Material Lots, Remnants |
| Production | Execution → Production Runs, Run Reporting, Downtime (R2) · Output → Production Postings, Rework Orders (R2) · Reconciliation → Run Reconciliation, Measurements |
| Quality | Inspection → Pending QC Queue, Inspection Lots, Hold Lots (R2) · Master Data → Defect Codes (R2), Sampling Plans (R2) |
| Inventory | Stock → Stock Overview, Raw Material, Semi-Finished Material, Finished Goods, Material at Production · Ledger → Movement Ledger, Allocations · Control → Stock Count (R2), Adjustments, Reversals, Period Close (R2) |
| Warehouse | Inbound → FG Receipt, Putaway · Outbound → Handling Units, Picking, Packing (R2) |
| Delivery | Shipping → Delivery Orders, Shipments, Proof of Delivery (R2) · Monitoring → Outstanding Demand |
| Maintenance (R2) | Assets → Machines, Dies & Tooling · Work → Breakdowns, Preventive Maintenance |
| Traceability | Lot Trace → Backward Trace, Forward Trace, Genealogy Graph (R2) · Reconciliation → Reconciliation Cases |
| Analytics | Insights → Dashboards (R2), Reports · Data Exchange → Export Center, Import Jobs (R2) |
| Administration | Organization → Company, Plants & Warehouses, Work Centers · Access → Users, Roles & Permissions, Approval Policies · System → Audit Log, Notification Settings (R2), Tenant Settings |
Label notes: the owner's example item "Material Loss" is labelled **Material Variance** (BR-REC-02); "Finish Good" is written **Finished Goods**. Both await owner confirmation (O-017).

## 8. Responsive behaviour
| Width | Behaviour |
|---|---|
| ≥ 1280 | Primary + secondary sidebar docked; secondary collapsible |
| 1024–1279 | Primary docked; secondary opens as overlay from toggle or module click; workspace full width |
| 768–1023 | As above; topbar hides context labels, period selector and user name (period moves to page filter bar) |
| < 768 | Hamburger opens primary + secondary as a sheet; topbar keeps breadcrumb, search icon, workspace icon, bell, avatar. Operational floor tasks use the PWA bottom navigation instead (EORA-PAGE-TEMPLATES.md §5) |
Escape closes overlays; focus returns to the trigger.

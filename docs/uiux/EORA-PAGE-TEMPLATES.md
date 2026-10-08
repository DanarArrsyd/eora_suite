# EORA Page Templates

**Status:** DECIDED (D-021, ADR-0010 + Amendment 1) · **Canonical for:** page templates and the status-tab validation rule. Components: [EORA-COMPONENT-STANDARDS.md](EORA-COMPONENT-STANDARDS.md). Prototypes: `design/mockups/01..04`.

## 1. `EoraEnterprisePage` — list workspace (mockup 04)
```
Breadcrumb (topbar)
Page title · description                                   [Primary action]
VIEW BY  All 11 │ Unrestricted 6 │ Allocated 2 │ At production 3 │ Hold 1 │ Blocked 1
[🔍 Search…] [⚙ filters] 214 lots     [Views ▾] [Import] [Export ▾] │ [Comfortable|Compact] [▥ columns]
┌Filter by│Plant ▾│Warehouse ▾│Material type ▾│Grade ▾│Receipt date 📅│Apply│Reset┐
Active: (Plant A) (RM-WH1 ×)  Save as view
│        │          │ Warehouse stock (base UOM)              │               │          │         │       │
┌☐│Lot 📌│Material│Available│Allocated│Hold│Blocked│At production│Location│Received│View ⋯ 📌┐
Showing 1–20 of 214 · Items per page [20▾] · Go to page [  ][→]     « First ‹ Prev ① 2 3 … 11 Next › Last »
(📌 = pinned column · phones: rows reflow to cards, filter bar becomes a bottom sheet)
(Optional) Detail drawer on row View
```
Order is fixed: breadcrumb → title/description → primary actions → status tabs → search & filters → contextual toolbar → content → pagination → optional drawer.

## 2. Status-tab validation rule
Tabs are presets over **approved** dimensions only (ADR-0002, DD-01, DD-03):
| Tab (Raw Material example) | Maps to | Not allowed |
|---|---|---|
| All | no extra filter | — |
| Unrestricted | `stock_status = UNRESTRICTED` in warehouse locations | — |
| Allocated | `stock_allocations.active > 0` | Treating "Reserved" as a stock status |
| At production | `location_type = MAT_AT_PROD` | "WIP" |
| Hold | `stock_status = HOLD` | — |
| Blocked | `stock_status = BLOCKED` | — |
The owner's example tab "Available" is shown as the **Available** bucket in the quantity cell (Unrestricted − allocated) rather than a tab, because it is a computed quantity, not a status. Any new tab needs a mapping row here before it is designed.

## 3. Transaction detail template (mockup 02)
Page header (ID + status badges + actions) → header card (definition list of key fields) → tabs (*Summary · Ledger postings · Measurements & evidence · Investigation · Audit trail*) → Summary: section cards + right aside (case/approval). Formulas and audit behind tabs or "Show calculation"; status, quantities, variance results and case state always visible. Posted documents are read-only; actions are Print, Export, Trace, Request reversal (approval).

## 4. Workspace / control tower template (mockup 01)
Page header with as-of and scope → filter bar → **max 4 decision KPI cards** (each: label, value + unit, definition line, drill link; no invented deltas) → `MaterialFlowStrip` → exceptions table + one chart whose title states its question → tabbed detail tables (Physical material, Estimated conversion, Production output, Quality status, FG stock, Shipments). Physical stock, estimated conversion, output, quality, FG and shipment are visually separate.

**Implemented (Phase 3, 2026-10-09):** `/ppic/control-tower` follows this order — filter bar → 4 decision KPI cards (definition, source, basis, meaning, as-of, drill link) → MaterialFlowStrip (kg/sheet band | transformation | pcs band) → exception queue + one question-titled chart → links to the detail screens. Deviation (approved performance rule, Phase 3 brief §8): the tabbed detail tables are separate server-paged screens (CT-1 … CT-10) instead of tabs loading all transactions on the overview.

## 5. Mobile task template (PWA, mockup 03)
Navy app header (title + context + one icon action, 44 px) → content cards → bottom navigation *Home · Tasks · Scan (centre) · Inbox · Profile* with safe-area padding; Tasks and Inbox carry actionable numeric badges only (navigation doc §10). The PWA never shows the desktop dual sidebar. Inputs: steppers with numeric keypad (`inputmode`), 48 px targets, units under values. Flows: scan → validate (server) → enter → review → post → server-confirmed result with document number. Offline: banner + "Save draft"; submit disabled with reason. Errors say what failed, that nothing was posted, and the next action. Screens: My Shift, My Tasks, Scan Material, Production Reporting, Quality Inspection, Material Confirmation, Notification Inbox, User Profile.

## 6. Other templates (to be mocked when their module is designed)
Master data form (`EoraForm` in drawer or page) · approval inbox (list + review drawer) · import wizard (stepper) · report viewer (`EoraDocumentViewer`, A4/A3 preview, grayscale-safe) · planning board (V2).

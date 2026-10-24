# EORA Component Standards

**Status:** DECIDED (D-021, ADR-0010 + Amendment 1 / D-026) · **Canonical for:** component layering, contracts, filter bar, data table, states, accessibility and QA checklist. Tokens: [EORA-DESIGN-TOKENS.md](EORA-DESIGN-TOKENS.md). Shell: [EORA-NAVIGATION-ARCHITECTURE.md](EORA-NAVIGATION-ARCHITECTURE.md). Prototype CSS: `design/mockups/assets/eora-ui.css`.

## 1. Layers (composition over duplication)
| Layer | Package | Contents | May depend on |
|---|---|---|---|
| 1. Tokens | `packages/design-tokens` | CSS variables, Tailwind `@theme`, shadcn variable mapping | — |
| 2. UI primitives | `packages/ui` | shadcn/ui (Radix) wrapped as `EoraButton`, `EoraInput`, `EoraSelect`, `EoraDatePicker`, `EoraModal` (Dialog/AlertDialog), `EoraDrawer` (Sheet), `EoraTabs`, `EoraTooltip`, `EoraBadge`, `EoraCheckbox` | tokens |
| 3. Shared patterns | `packages/features` | `EoraAppShell`, `EoraPrimarySidebar`, `EoraSecondarySidebar`, `EoraTopbar`, `EoraBreadcrumb`, `EoraPageHeader`, `EoraStatusTabs`, `EoraFilterBar`, `EoraDataTable`, `EoraPagination`, `EoraStatusBadge`, `EoraForm`/`EoraTransactionForm`, `EoraNotificationCenter`, `EoraApprovalPanel`, `EoraImportWizard`, `EoraExportMenu`, `EoraDocumentViewer`, `EoraEmptyState`, `EoraLoadingState`, `EoraErrorState`, `EoraAuditTimeline` | primitives, api-client |
| 4. Business components | `apps/web/src/modules/<module>/components` | `QuantityWithUom`, `QuantityBreakdownCell`, `ConversionBadge`, `StockStatusChip`, `MaterialFlowStrip`, `ConservationLine`, `VarianceIndicator`, `LocationChip`, `ExceptionQueue`, `MeasureCapture`, `QuantityStepper` | patterns |
| 5. Page composition | `apps/web/src/app/(modules)/…` | Pages compose templates + config; no styling decisions | all above |
Rules: a shared pattern never imports a business module; no component exceeds one responsibility; module differences are passed as configuration (schemas, column defs, actions), not `if (module === …)` branches.

## 2. Primitives (summary)
| Component | Contract highlights |
|---|---|
| `EoraButton` | variants `primary` (one per view region), `secondary`, `ghost`, `link`, `destructive`; sizes `md` 36 px, `sm` 32 px; `loading` disables and keeps width; double-submit guarded |
| `EoraInput` / `EoraSelect` / `EoraDatePicker` | label required (visible or `aria-label`), hint and error slots wired with `aria-describedby`, `aria-invalid`; control border `--color-border-control`. **No native `<select>` or `<input type="date">` (owner, 2026-10-14):** `Select` is a Radix listbox (keyboard, typeahead, clear item); dates use `Calendar` / `DatePicker` / `DateRangePicker` — month grid with ISO weeks (Monday first, week numbers, D-044), arrows/Home/End/PageUp/PageDown, Today and Clear, range preview |
| `EoraModal` | Dialog for forms; **AlertDialog** for destructive/irreversible confirmation |
| `EoraDrawer` | right sheet 520 px (full width < 768), focus trap, Esc closes, focus returns |
| `EoraStatusBadge` | maps a domain status to tone + optional icon; text always present; tones: success, warning, danger, info, neutral |

## 3. EoraStatusTabs
Radix Tabs. Optional "View by" label, counts from server. Arrow/Home/End keys. Tabs on list pages are **saved filters** over approved dimensions only (EORA-PAGE-TEMPLATES.md §2).

## 4. EoraFilterBar (schema-driven, one pattern everywhere)
```ts
type FilterField = { key: string; label: string; locked?: boolean /* workspace scope chip */ } & (
  | { type: 'text' | 'search'; placeholder?: string }
  | { type: 'select' | 'multiselect'; source: 'static'; options: Option[]; dependsOn?: string[] }
  | { type: 'select' | 'multiselect'; source: 'api'; loadOptions: (deps, signal) => Promise<Option[]>; dependsOn?: string[] }
  | { type: 'daterange' }                                   // value travels as `from..to`
  | { type: 'entity'; entity: 'customer'|'company'|'plant'|'warehouse'|'material'|'part'|'work_order'|'machine'|'shift'|'status'; loadOptions; dependsOn?: string[] }
);
```
(Implemented in `packages/features/src/patterns/filter-bar/types.ts`; `loadOptions` wraps the schema endpoint so the component never builds URLs.)
```
- Schema from `GET /api/v1/query/{resource}/schema` (ADR-0008), already filtered by permission.
- **One form element, two presentations** (Amendment 1): segmented bar on ≥ 768 px (`Filter by │ field ▾ │ … │ Apply │ Reset`); the **same form** becomes a bottom sheet on phones, opened by a "Filters (n)" button — dialog semantics, Close button, Esc, backdrop, focus trap, body scroll lock, 44 px controls, 16 px input text (prevents iOS zoom). No second filter component.
- Behaviour: Apply/Reset, active-filter chips (scope chips locked, others removable), saved views (menu: my views, shared views, "Save current filters…"; stored server-side), URL query sync, dependent filters (plant → warehouse → location: child options re-query when the parent changes), server-side filtering only, filtered-empty state with "Clear filters".
- Toolbar order (list pages): search · filter toggle · record count │ Views ▾ · Import · Export ▾ (Excel, PDF, Print) · density · column settings.

## 5. EoraDataTable (TanStack Table) — one table for every module
- `manualPagination`, `manualSorting`, `manualFiltering`; server returns rows + `meta {total|has_more, as_of, timezone}`. Never load all rows for client pagination.
- Columns from QueryDefinition schema + page column defs (format only). Column visibility, order and width saved per user. Resizing on wide data tables only.
- **Density presets** (Amendment 1, D-026; default **Comfortable** for new users, D-029) — a per-user preference applied to every table. Compact never hides critical information, quantity/UOM or critical operational status; columns marked `critical` in the column definition are exempt from any compact-mode hiding. Usable with mouse, keyboard and touch, switched from the table toolbar (`Comfortable | Compact`, `aria-pressed`):
  | Token | Comfortable (default) | Compact |
  |---|---|---|
  | Row height | 44 px | 34 px |
  | Header height | 38 px | 32 px |
  | Cell padding x | 12 px | 10 px |
  | Body text | 13 px | 12.5 px |
  | Secondary sub-line | visible (12 px) | hidden — reachable via detail drawer or row expansion (tooltip only as supplement, D-029) |
  | Status badge | 22 px | 20 px |
  | Row action buttons | 32 px | 28 px (spacing keeps ≥ 24 px target rule) |
  Rows never wrap: text columns are single-line with ellipsis + `title` (`text-col`, 200–280 px), so preset heights are exact (verified 44/34 px).
- **Quantities**: `QuantityWithUom` — value + UOM in one element (`700.000 kg`, `80 sheet`, `760 pcs`); kg 3 decimals, pcs/sheet 0. Zero shown as "–" (screen-reader text "zero"). Multi-status quantities use **grouped columns** (two-row header: group label + bucket columns) — never a stacked mini-label cell, never colour-only dots. Footer totals only within one UOM.
- **Status** uses `EoraStatusBadge` (text always present); never adds row height beyond the preset.
- **Row actions**: one visible primary action (e.g. View) + overflow menu (`aria-haspopup="menu"`, arrow keys, Esc, focus return). Unavailable actions stay visible but disabled **with the reason** (e.g. "Requires Inventory Controller role").
- **Horizontal scroll** only inside the table container (`overscroll-behavior-x: contain`), never the page. Selection + identity columns pinned left, actions pinned right (`sticky-start`, `sticky-end`) with scroll shadows.
- **Header**: sticky; sortable headers expose `aria-sort`; group header row sticky above.
- **Selection** + bulk-action bar (permission-aware); selected rows use `--color-bg-selected` and `--color-text-secondary-strong` for secondary text.
- **Phones (< 768 px)**: list tables reflow into cards (`data-label` per cell, identity line first, zero buckets hidden, actions at the bottom with 44 px targets); data-dense analytical tables keep the scroll container.
- **States**: skeleton rows (loading, `aria-busy`), filtered-empty, first-run empty, error with retry + reference ID (`role="alert"`), no-permission, stale (`as_of` warning).

## 6. EoraPagination — identical in every module
`Showing a–b of N` · Items per page (20/50/100, persisted) · Go to page · First / Prev / numbered pages / Next / Last (cursor mode: Prev/Next only). Phones: summary + current page + Prev/Next (44 px). Rendered by one shared component (`eora-table.js` in prototypes) — pages never build their own pager.

## 7. Other shared patterns
- `EoraPageHeader`: title, description, meta line (as-of, scope), primary/secondary actions.
- `EoraNotificationCenter`: see navigation doc §5 and doc 07.
- `EoraApprovalPanel`: maker → reviewer → approver cards, SoD note, step-up indicator; actions open review screens.
- `EoraExportMenu`: Excel/PDF using the same query scope; async job + bell notification; Print uses print CSS.
- `EoraImportWizard`: template → upload → validate → preview/errors → approval → idempotent commit (doc 08). The live master-data import (M2 step 6d, D-081) runs the same steps as pages — New import, then a job page that follows the worker and is submitted and committed by different people — because checking is asynchronous and the commit may wait for someone else; the modal stays for short synchronous imports.
- `EoraEmptyState` / `EoraLoadingState` / `EoraErrorState`: icon, title, cause, next action; never a bare spinner or "No data".
- Business: `MaterialFlowStrip` groups stages into bands by UOM (Physical material kg │ Transformation │ Production & quality pcs │ FG & shipment pcs) and never sums across the boundary; grouped quantity columns show disjoint buckets that add up to on-hand (~~`QuantityBreakdownCell`~~ superseded by Amendment 1); `ConversionBadge` shows Calculated/Provisional/Not calculable + missing inputs; `VarianceIndicator` shows value, %, tolerance result, status and a "Show calculation" disclosure.

## 8. Accessibility rules
WCAG 2.2 AA; semantic landmarks (`nav`, `header`, `main`); visible 2 px focus ring; tooltips on hover and focus; all icon-only buttons have accessible names; status never colour-only; tables use `scope`, `aria-sort`; dialogs trap focus and restore it; live regions for async results; targets ≥ 24 px desktop, ≥ 48 px PWA; `prefers-reduced-motion` respected; text resizable to 200 % without loss.

## 8a. Accessibility verification on rendered pages (2026-10-08, Playwright, prototypes v2.1)
Method: every visible text node's computed colour against its effective (blended) background; control borders vs surrounding background; focus ring on 30 consecutive Tab stops; interactive target sizes. States covered: default, hover, selected row, error state, form validation errors, notification panel open, mobile navigation open, mobile PWA.
| Check | Result |
|---|---|
| Text contrast (≈ 1,950 text nodes across 10 states) | All ≥ 4.5:1 (normal) / ≥ 3:1 (large) after one fix (PWA inactive segmented tab 4.34 → `--color-text-secondary-strong`) |
| Input / select / filter / checkbox boundaries | All ≥ 3:1 (`--color-border-control`) |
| Focus indicator | 2 px `--color-focus-ring` outline visible on all 30 tab stops tested (menus use inset ring) |
| Target size desktop (≥ 24 px) | Pass; 18 px table checkboxes rely on the WCAG 2.5.8 spacing exception (each sits alone in a 44 px cell) |
| Target size phones (≥ 44 px PWA / ≥ 24 px web) | Pass after fixes (header icons 40 → 44, switches 28 → 44 hit area, checkboxes 24 px on phones) |
| Disabled states | Disabled buttons use neutral fill + secondary text and keep a reason (tooltip/sub-text); exempt from contrast but still legible (4.34:1) |
| Error messages | `--color-danger-fg` on white 8.31:1, icon + text, `aria-invalid` + `aria-describedby` |
Not yet verified: screen-reader walkthrough (NVDA/VoiceOver), 200 % zoom reflow, Windows high-contrast mode, real-device touch testing.

## 9. QA checklist (before showing any UI)
Typography (Inter only, scale respected) · palette (tokens only, one accent) · navigation badges actionable only (navigation doc §10) · density presets exact · rendered-page contrast audit (§8a method) · spacing scale · icons (Lucide, one size per context) · buttons and form controls consistent · filter bar and data table consistent with this file · widths 1920/1440/1024/768/390/375 with no page-level horizontal scroll · keyboard path through shell, tabs, filters, table, drawer · contrast per tokens doc · overflow/truncation with tooltips · touch targets · empty/loading/error/no-permission states · business labels per constitution §4 · sample data labelled.

## 10. Phase 2 implementation map (D-036, 2026-10-08)
| Standard | Implementation | Notes |
|---|---|---|
| `EoraEnterprisePage` + list order (PAGE-TEMPLATES §1) | `EnterprisePage`, `ListWorkspace` | slot order fixed in code: tabs → toolbar → filter bar → active filters → bulk bar → content → footnote → pagination |
| `EoraStatusTabs` | `StatusTabs` | counts in accessible names (`All, 214`) |
| `EoraStatusBadge` | `DomainStatusBadge` + `STATUS_CATALOGUE` | domains: stock (DD-01), document (doc 16), schedule (D-034), indicator (ADR-0005); never mixed |
| `EoraFilterBar` | `FilterBar`, `FilterToggle`, `ActiveFilters`, `SavedViewsMenu`, `ListSearch` | one schema → segmented bar ≥ 768 px / bottom sheet on phones; URL sync via `useListUrlState`; dependent fields clear children; locked scope never written to the URL |
| `EoraDataTable` | `DataTable` (TanStack Table v9 inside) | modules use `EoraColumn<T>` only; `critical` columns cannot be hidden; `emptyOnPhone` hides zero buckets in cards; disabled actions stay focusable (`aria-disabled`) with the reason |
| Density (§5) | `DensityToggle` + `useDensityPreference` | Comfortable default (D-029); exact 44/34 px rows, 38/32 px headers (E2E-measured); hidden on phones (cards) |
| `EoraPagination` | `Pagination` | numbered window with gaps; phones: summary + current + Prev/Next (44 px) |
| `EoraForm` | `EoraForm`, `FormTextField`, `FormSelectField`, `FormTextareaField`, `FormQuantityField` | RHF + Zod; summary receives focus; server field errors mapped back; one idempotency key until success |
| `EoraModal` / AlertDialog | `ModalContent` + `ModalBody` / `ModalFooter`, `ConfirmDialog` | ConfirmDialog: focus on Cancel, optional mandatory reason (audit), errors stay in the dialog |
| `EoraDrawer` | `DrawerContent` | focus returns to the invoking element even when opened from state |
| Feedback | `FeedbackProvider` / `useFeedback`, `InlineAlert` | toasts for background results only; persistent outcomes use InlineAlert / inbox |
| States | `LoadingState`, `EmptyState`, `ErrorState`, `PermissionDeniedState`, `Skeleton`, stale banner | error shows reference id, never a stack trace |
| `EoraExportMenu` / `EoraImportWizard` | `ExportMenu`, `ImportWizard` (`ExportSource`, `ImportSource`) | export = same query scope as the table, queued job; import = template → upload → validate → review errors → approval → idempotent commit |
| `EoraNotificationCenter` | `NotificationCenter` (`NotificationSource`) | bell panel + `/system/notifications`; Needs action / Unread / All; deep links only when the role can open the module |
Engineering rule learned in Phase 2: anything rendered **above** a page's Suspense boundary (providers, shell) must not change context value during hydration — see ADR-0011 Amendment 1.

## 11. Phase 3 business components (Control Tower, 2026-10-09)
Built in `apps/web/modules/ppic/components` (layer 4 business components) on top of the shared patterns — no new shared pattern was needed:
| Component | Rule |
|---|---|
| `CtScreen` | configuration-only composition of EnterprisePage + ListWorkspace + StatusTabs + FilterBar + DataTable + Pagination + ExportMenu + detail drawer; screens differ by config |
| `CtDetailDrawer` | definition lists, "Show calculation" disclosure, source-document drill links |
| `KpiCard` | label, value + UOM (secondary quantity with its own UOM), definition, source, basis, meaning, as-of, drill link; no deltas |
| `MaterialFlowStrip` | UOM bands with an explicit transformation boundary; never sums across it |
| `ConversionBadge`, `EstimatedPcs` | Calculated / Provisional / Not calculable — missing inputs; estimates always labelled "est." |
| `IndicatorCell` | signed value + % + indicator status (ADR-0005); never "loss" |
| `MethodLabel`, `RiskReasons`, `FreshnessBadge` | measurement method (by-difference highlighted), CT-8 reason codes, read-model lag with stale warning |
| `DueVsSupplyChart` | one axis (pcs) per part, validated palette (`--viz-demand`, `--viz-supply`), legend, risk labels, focusable days with tooltip, table view |
`QuantityWithUom` (apps/web/components/quantity.tsx) is used for every quantity cell.


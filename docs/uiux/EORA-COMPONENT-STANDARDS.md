# EORA Component Standards

**Status:** DECIDED (D-021, ADR-0010) · **Canonical for:** component layering, contracts, filter bar, data table, states, accessibility and QA checklist. Tokens: [EORA-DESIGN-TOKENS.md](EORA-DESIGN-TOKENS.md). Shell: [EORA-NAVIGATION-ARCHITECTURE.md](EORA-NAVIGATION-ARCHITECTURE.md). Prototype CSS: `design/mockups/assets/eora-ui.css`.

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
| `EoraInput` / `EoraSelect` / `EoraDatePicker` | label required (visible or `aria-label`), hint and error slots wired with `aria-describedby`, `aria-invalid`; control border `--color-border-control` |
| `EoraModal` | Dialog for forms; **AlertDialog** for destructive/irreversible confirmation |
| `EoraDrawer` | right sheet 520 px (full width < 768), focus trap, Esc closes, focus returns |
| `EoraStatusBadge` | maps a domain status to tone + optional icon; text always present; tones: success, warning, danger, info, neutral |

## 3. EoraStatusTabs
Radix Tabs. Optional "View by" label, counts from server. Arrow/Home/End keys. Tabs on list pages are **saved filters** over approved dimensions only (EORA-PAGE-TEMPLATES.md §2).

## 4. EoraFilterBar (schema-driven, one pattern everywhere)
```ts
type FilterField =
  | { key: string; type: 'text' | 'search'; label: string }
  | { key: string; type: 'select' | 'multiselect'; label: string; source: 'static' | 'api'; options?: Option[]; endpoint?: string; dependsOn?: string[] }
  | { key: string; type: 'date' | 'daterange'; label: string; presets?: string[] }
  | { key: 'customer'|'company'|'plant'|'warehouse'|'material'|'part'|'work_order'|'machine'|'shift'|'status'; type: 'entity'; label: string; dependsOn?: string[] };
```
- Schema comes from `GET /api/v1/query/{resource}/schema` (ADR-0008), already filtered by permission.
- Variants: **segmented** (default on list pages, reference style) and **stacked** (forms/side panels, mobile sheet).
- Behaviour: Apply/Reset, active-filter chips with remove, saved views (server-side, per user/role), URL query sync, dependent filters (plant → warehouse → location), server-side filtering only, filtered-empty state with "Clear filters".
- Keyboard: every segment reachable by Tab; Enter applies.
- Only field configuration, data source and permission differ per page. No custom filter UI per module.

## 5. EoraDataTable (TanStack Table)
- `manualPagination`, `manualSorting`, `manualFiltering`; server returns rows + `meta {total|has_more, as_of, timezone}`. Never load all rows for client pagination.
- Columns from QueryDefinition schema + page column defs (format only). Column visibility, order and width saved per user. Resizing on wide data tables only.
- Visual: header `--color-bg-subtle`, 12 px semibold secondary text; rows 44 px (60 px for compound cells); hover `--color-bg-subtle`; selected `--color-bg-selected`; sticky header inside the scroll container; horizontal scroll inside the card, never the page.
- Alignment: text left; numbers, quantities, money, percentages **right** with tabular numerals; dates `DD Mon YYYY` (tenant locale) and times `HH:mm` + timezone in page meta.
- Quantities: `QuantityWithUom` — `700.000 kg`, `80 sheet`, `760 pcs`. Decimal places per UOM (kg 3, pcs 0). Footer totals only within one UOM.
- Row selection + bulk-action bar (permission-aware). Row actions: one visible primary action + overflow menu; unavailable actions hidden or disabled with reason.
- States: skeleton rows (loading, `aria-busy`), filtered-empty, first-run empty, error with retry + reference ID, no-permission, stale (`as_of` warning).

## 6. EoraPagination
Items per page (20/50/100), go-to-page, First/Prev/pages/Next/Last; cursor mode shows Prev/Next only. Page size persisted per user.

## 7. Other shared patterns
- `EoraPageHeader`: title, description, meta line (as-of, scope), primary/secondary actions.
- `EoraNotificationCenter`: see navigation doc §5 and doc 07.
- `EoraApprovalPanel`: maker → reviewer → approver cards, SoD note, step-up indicator; actions open review screens.
- `EoraExportMenu`: Excel/PDF using the same query scope; async job + bell notification; Print uses print CSS.
- `EoraImportWizard`: template → upload → validate → preview/errors → approval → idempotent commit (doc 08).
- `EoraEmptyState` / `EoraLoadingState` / `EoraErrorState`: icon, title, cause, next action; never a bare spinner or "No data".
- Business: `MaterialFlowStrip` groups stages into bands by UOM (Physical material kg │ Transformation │ Production & quality pcs │ FG & shipment pcs) and never sums across the boundary; `QuantityBreakdownCell` shows disjoint buckets that add up to on-hand; `ConversionBadge` shows Calculated/Provisional/Not calculable + missing inputs; `VarianceIndicator` shows value, %, tolerance result, status and a "Show calculation" disclosure.

## 8. Accessibility rules
WCAG 2.2 AA; semantic landmarks (`nav`, `header`, `main`); visible 2 px focus ring; tooltips on hover and focus; all icon-only buttons have accessible names; status never colour-only; tables use `scope`, `aria-sort`; dialogs trap focus and restore it; live regions for async results; targets ≥ 24 px desktop, ≥ 48 px PWA; `prefers-reduced-motion` respected; text resizable to 200 % without loss.

## 9. QA checklist (before showing any UI)
Typography (Inter only, scale respected) · palette (tokens only, one accent) · spacing scale · icons (Lucide, one size per context) · buttons and form controls consistent · filter bar and data table consistent with this file · widths 1920/1440/1024/768/390/375 with no page-level horizontal scroll · keyboard path through shell, tabs, filters, table, drawer · contrast per tokens doc · overflow/truncation with tooltips · touch targets · empty/loading/error/no-permission states · business labels per constitution §4 · sample data labelled.

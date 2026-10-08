# ADR-0010 — Modern Corporate Enterprise Workspace (supersedes ADR-0009)

- **Status:** Accepted direction (D-021); **final mockup approval pending** before production frontend · **Date:** 2026-10-08 · **Supersedes:** ADR-0009

## Context
The owner reviewed the ADR-0009 prototypes (Ledger Ink desktop, Andon Signal floor mode, condensed IBM Plex/Barlow typography, monospace numerals) and rejected them as rigid, dated and terminal-like. The owner requires a modern corporate ERP workspace, one theme, Inter, dual sidebar navigation and a shared design system, using an inventory-ERP screenshot as the structural reference.

## Decision
1. Direction **EORA Modern Corporate Enterprise Workspace**, single theme **Modern Corporate Blue** (owner palette, no dark mode or theme switching).
2. **Inter** as the only UI typeface; tabular numerals; monospace only for technical codes.
3. Desktop shell: **primary icon sidebar + grouped secondary sidebar + topbar** (breadcrumb, permission-aware search, page context, company/plant switcher, notifications, profile). Mobile PWA: bottom navigation with central Scan.
4. Shared design system on shadcn/ui + Radix + Tailwind + TanStack Table/Query + Lucide; five-layer component architecture.
5. Canonical specifications in `docs/uiux/` (constitution, tokens, navigation, components, page templates); prototypes in `design/mockups/` (01–04).
6. Kept from ADR-0009: `MaterialFlowStrip` as a business visualization (redesigned as UOM-grouped bands), `QuantityWithUom`, `ConversionBadge`, `VarianceIndicator`, exception-first Control Tower, all business-rule protections.
7. Contrast adjustments within the palette: added `--color-border-control #7F8EA3`; muted text limited to disabled/decorative use; badge foregrounds use darker status shades.

## Consequences
+ Familiar ERP ergonomics, consistent modules, smaller learning curve.
+ One theme reduces design/test surface.
− Inter and Lucide are common choices; identity comes from navigation structure, business components and disciplined colour use rather than typography (owner decision, recorded as a skill conflict with frontend-design/antislop guidance).
− Topbar density needs rules at 1280–1440 px (company+plant combined, labels hide < 1680 px).

## Verification
Prototype QA at 1920/1440/1024/768/390/375 px (overflow, keyboard, states) recorded in the delivery report; owner final approval recorded in doc 13 before any `apps/web` work.

## Amendment 1 — UX risk refinement (2026-10-08, D-024..D-026)
**Context:** Owner conditionally approved mockups 01–04 as the design baseline (O-016) and required the identified UX risks to be resolved before any production frontend.

**Decisions (prototype + spec level):**
1. **Responsive topbar priorities** — never hidden: current-page crumb, bell, profile, company/plant access. Search input → icon + command dialog below 1600 px; middle crumbs collapse to "…"; page context and workspace become icons below 1024 px and move into an overflow menu below 768 px. Bell and profile stay at the right end (O-018).
2. **Mobile navigation sheet** — labelled Menu trigger, drill-down sheet (current module menu ⇄ module list), backdrop, Close, Esc, focus trap and return, body scroll lock, `aria-expanded` / `aria-modal`. Desktop dual sidebar is never shown on phones.
3. **Actionable numeric badges only** (D-025) — sources: approvals, QC inspections, variance investigations, assigned tasks, action-required notifications; tenant- and permission-scoped, computed server-side. Supersedes the amber attention dot and record-count pills.
4. **DataTable density presets** — Comfortable 44 px / Compact 34 px rows as a per-user preference in one shared table implementation; grouped quantity columns replace the stacked compound cell; single-line text columns; pinned identity/action columns; row overflow menu with disabled-with-reason items; one pagination component; phone card reflow.
5. **Filter bar** — one form element rendered as segmented bar (≥ 768 px) or bottom sheet (phones); saved views and dependent filters.
6. **Terminology** (D-024) — Material Variance for menus/indicators; Actual Material Loss and Physical Stock Discrepancy kept as proven outcomes.
7. **Accessibility** — token `--color-text-secondary-strong` added; rendered-page audit procedure and results recorded in EORA-COMPONENT-STANDARDS.md §8a.

**Consequences:** + Topbar, tables and navigation hold from 1920 to 375 px without page-level horizontal scroll (verified). − Compact density hides secondary sub-lines (available via tooltip/drawer). − Screen-reader and real-device testing still outstanding.

**Status:** Accepted as refinement; production frontend still requires explicit owner approval.

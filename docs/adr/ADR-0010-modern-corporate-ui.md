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

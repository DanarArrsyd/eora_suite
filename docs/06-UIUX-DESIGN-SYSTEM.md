# 06 — UX, Design System & Consistent Page Templates

> **Updated 2026-10-08 (D-021, ADR-0010 + Amendment 1: D-024..D-026).** This file is now the **entry point**. The approved design system lives in `docs/uiux/`; each topic has exactly one canonical document. Do not restate rules here — link to them.

| Need | Canonical document |
|---|---|
| Direction, principles, business-rule protection, mandatory skill workflow | [uiux/EORA-DESIGN-CONSTITUTION.md](uiux/EORA-DESIGN-CONSTITUTION.md) |
| Colour, typography, spacing, radius, elevation, contrast | [uiux/EORA-DESIGN-TOKENS.md](uiux/EORA-DESIGN-TOKENS.md) |
| App shell, dual sidebar, topbar, navigation config, module menus | [uiux/EORA-NAVIGATION-ARCHITECTURE.md](uiux/EORA-NAVIGATION-ARCHITECTURE.md) |
| Component layers, FilterBar, DataTable, states, accessibility, QA checklist | [uiux/EORA-COMPONENT-STANDARDS.md](uiux/EORA-COMPONENT-STANDARDS.md) |
| List, transaction detail, workspace and mobile task templates; status-tab rule | [uiux/EORA-PAGE-TEMPLATES.md](uiux/EORA-PAGE-TEMPLATES.md) |
| Prototype reference | `design/mockups/index.html` |
| Filter/export parity mechanism | [adr/ADR-0008-query-definition.md](adr/ADR-0008-query-definition.md) |

## Still valid from the original specification
- Original enterprise workspace; never copy SAP/Oracle or reference-app visuals. Desktop for planning/control, PWA for scan/input/approve.
- Shared packages: `@eora/design-tokens`, `@eora/ui`, `@eora/features`, `@eora/api-client`, `@eora/config`; Storybook and visual regression tests.
- Schema-driven filters with identical server query scope for grid and export; no bespoke filter UI per page.
- Template taxonomy: list workspace, transaction detail, master data form, planning board, mobile task/scan, approval inbox, exception investigation, report preview; distinct error, empty, loading, partial, offline, unauthorized, expired-session and conflict states.
- UX acceptance: a role finishes top tasks without unnecessary cross-module navigation; errors identify exact field and recovery; no duplicate action on double click; timestamps and units visible; filters consistent; keyboard and touch tested with users.
- Component name: `EoraForm` (CLAUDE.md) and `EoraTransactionForm` refer to the same pattern.

## SUPERSEDED
- ~~Selected direction D-018: hybrid "Ledger Ink" / "Andon Signal" / "Traceline" (ADR-0009)~~ → superseded by D-021 / ADR-0010 on 2026-10-08. Archived prototypes: `design/mockups/_superseded-v1-ledger-ink/`.
- ~~Mockup gate requiring 3 distinct directions with light/dark options~~ → fulfilled; the owner selected one direction and **one theme without dark mode** (D-021). New gate: final approval of the Modern Corporate Blue mockups before production frontend.
- ~~Additional components FloorNumpad, AsOfStamp as Ledger Ink styling~~ → replaced by the component list in EORA-COMPONENT-STANDARDS.md.
- ~~Primary-sidebar amber attention dot and record-count menu pills~~ → actionable numeric badges only (D-025).
- ~~`QuantityBreakdownCell` stacked mini-label cell~~ → grouped quantity columns (Amendment 1).

## Mandatory frontend skill and visual quality gate
See [EORA-DESIGN-CONSTITUTION.md §6](uiux/EORA-DESIGN-CONSTITUTION.md). Every frontend/UI task must review the approved EORA Design System and use relevant installed Caveman and Frontend Design skills; missing skills are reported, never claimed. No production frontend before explicit owner approval.

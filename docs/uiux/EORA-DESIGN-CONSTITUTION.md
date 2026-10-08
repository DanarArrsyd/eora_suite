# EORA Design Constitution

**Status:** DECIDED (D-021, ADR-0010) · **Date:** 2026-10-08 · **Canonical for:** design direction, principles, rules, workflow. Other UI/UX documents reference this file and do not restate its rules.

| Topic | Canonical document |
|---|---|
| Direction, principles, rules, workflow, business-rule protection | **this file** |
| Colour, type, spacing, radius, elevation, motion tokens, contrast | [EORA-DESIGN-TOKENS.md](EORA-DESIGN-TOKENS.md) |
| App shell, primary/secondary sidebar, topbar, navigation config | [EORA-NAVIGATION-ARCHITECTURE.md](EORA-NAVIGATION-ARCHITECTURE.md) |
| Component layers, contracts, FilterBar, DataTable, states, a11y | [EORA-COMPONENT-STANDARDS.md](EORA-COMPONENT-STANDARDS.md) |
| Page templates (list, detail, workspace, mobile task) | [EORA-PAGE-TEMPLATES.md](EORA-PAGE-TEMPLATES.md) |
| Prototype reference implementation | `design/mockups/` (index.html) |

## 1. Direction
**EORA Modern Corporate Enterprise Workspace** — theme **Modern Corporate Blue**. Modern, professional, clean, structured, familiar, information-rich and efficient. It should feel like a mature ERP the user already knows how to operate, not like a terminal, a pasted spreadsheet, a template dashboard or an unstyled prototype.

Supersedes ADR-0009 (Ledger Ink / Andon Signal / condensed industrial typography), which the owner rejected as rigid and dated.

## 2. Non-negotiable visual decisions
1. **One theme.** No dark mode, no light/dark toggle, no auto theme, no theme selector, no presets. Desktop and PWA share the same identity.
2. **Inter** is the only interface typeface (fallback Arial, system-ui, sans-serif). Tabular numerals for aligned numbers. Monospace only for technical codes (e.g. bin/location codes) where it helps scanning.
3. **Dual sidebar on desktop**: slim primary icon sidebar (modules) + white secondary sidebar (feature menu grouped by business function). Mobile uses bottom navigation, never the dual sidebar.
4. **Semantic tokens only** in components. No raw hex outside the token file.
5. **Lucide icons**, one stroke weight, 16 px in controls and tables, 20 px in navigation. Icons always paired with a text label or accessible name.
6. Owner's reference screenshot (inventory ERP, 2026-10-08) is the primary **structural** reference: grouped secondary menu with counts, uppercase breadcrumb, "View by" tabs, search + record-count toolbar, segmented filter bar, compound quantity cell, row actions, pagination with go-to-page. Adapt patterns; never copy logo, brand, colours or pixel layout.

## 3. Principles (apply in this order when they conflict)
1. **Correct over pretty.** Labels, metrics, statuses and units follow docs 02, 15, 16, ADR-0002..0005. A visual change never alters a formula, a ledger posting or a state machine.
2. **Decision first.** Each screen names the decision its user makes there; layout puts the deciding information first (exceptions before charts, the deciding column early in tables).
3. **Familiar and consistent.** Same filter bar, table, tabs, badges, buttons and page template in every module; modules differ only by configuration, data source and permission.
4. **Dense but legible.** 13–14 px data, 44 px table rows, clear grouping, generous but not wasteful whitespace.
5. **Progressive disclosure, not hiding.** Formulas, audit and secondary data sit behind tabs/disclosure; critical decision data (status, quantity, variance, risk) is always visible.
6. **Honest states.** Every view designs loading, empty (first run vs filtered), error, no-permission, offline, stale, conflict and period-locked states, each naming the cause and the next action.
7. **Accessible by default.** WCAG 2.2 AA target, keyboard complete, visible focus, status never colour-only, touch targets ≥ 24 px desktop (≥ 44 px where touch is expected), ≥ 48 px on PWA.

## 4. Business-rule protection in the UI
- No WIP module, menu or label. Use *Material at Production*, *Remaining Material*, *Semi-Finished Material*.
- Quantities always show their UOM. Never add kg, sheet and pcs together; conversions show basis and certainty (*Calculated / Provisional / Not calculable*).
- Estimated pcs are planning estimates, visually separated from stock and never labelled stock.
- Pending QC ≠ QC Released ≠ FG Unrestricted ≠ Shipped — separate stages, never merged in one number.
- Variance indicators are GYV, PvS and UMV with status (*Not calculable / Provisional / Final*). The word **"loss"** appears only as *Loss Investigation* context or for a confirmed physical discrepancy from an approved stock count (BR-REC-02).
- Status tabs and filters are views over approved dimensions (stock status, allocation, location type). They never introduce a new stock status (DD-01).
- Posting is confirmed only after server acknowledgement; offline work is shown as *Draft*.
- Approvals open a review screen; never one-tap approve from a notification.
- RBAC-aware UI is convenience only; the server enforces every permission and tenant scope.

## 5. Anti-patterns (reject in review)
Excessive gradients, neon, glassmorphism, glow, decorative shadows, background grids; more than one accent colour; KPI rows with invented numbers or unnamed deltas; charts without a question in the title; generic `Name/Status/Date/Actions` columns; filler data that looks real; bare spinners or "No data"; per-module custom filter UIs; giant components with module-specific conditionals; hardcoded menus inside pages; copied SAP/Oracle or reference-app visuals.

## 6. Mandatory workflow for every frontend/UI task
**Every frontend/UI task must review the approved EORA Design System and use relevant installed Caveman and Frontend Design skills.**
1. Read this constitution and the canonical document for the area being changed.
2. Discover and read the installed **Caveman** skill and the relevant **Frontend / Frontend Design** skill(s) (plus anti-slop/UI skills where helpful) before working; apply their actual instructions.
3. Where a skill conflicts with an owner decision in this file (e.g. a skill discouraging Inter or Lucide, or defaulting to dark mode), the owner decision wins; record the conflict in the task's Skill Usage Report.
4. If a skill is missing, say so explicitly and do not claim to have used it.
5. Run the QA checklist in EORA-COMPONENT-STANDARDS.md §9 at 1920, 1440, 1024, 768, 390 and 375 px; never claim visual testing that was not performed.
6. Production frontend implementation requires explicit owner approval of the mockups (D-021 gate).

## 7. Change control
Any change to this file needs a decision entry in `docs/13-DECISION-REGISTER.md` and, for structural changes, an ADR. Superseded rules are marked, not deleted.

# 22 — Frontend Architecture & Phased Implementation

**Status:** DECIDED (D-033, ADR-0011) · **Canonical for:** frontend repository structure, package boundaries, phase plan, quality gates, mock-data policy and accessibility validation tracking. Visual rules live in `docs/uiux/` (do not restate them here).

## 1. Repository structure
```
package.json · pnpm-workspace.yaml · turbo.json · eslint.config.mjs · vitest.config.ts
.prettierrc.json · .nvmrc (Node 24) · .github/workflows/ci.yml
apps/
  web/                         Next.js App Router (UI only — no business logic, no DB access)
    app/                       routes: (workspace)/[module]/[item], system/ui-kit, error/not-found
    e2e/                       Playwright: responsive, accessibility (axe), keyboard, media preferences
packages/
  design-tokens/               tokens.css (CSS variables) + index.ts (typed exports)
  ui/                          primitives: Button, Input, Select, Field, Checkbox, Badge, StatusBadge, NumericBadge,
                               Card, Tooltip, Popover, DropdownMenu, Modal (+Body/Footer), Drawer, Tabs, Skeleton, Kbd,
                               EmptyState / ErrorState / LoadingState / PermissionDeniedState, InlineAlert, useReturnFocus
  features/                    shell (AppShell, PrimarySidebar, SecondarySidebar, Topbar, Breadcrumb,
                               MobileNavSheet, WorkspaceSwitcher, UserMenu, NotificationBell,
                               GlobalSearch trigger/dialog), PageHeader, navigation config + selectors + mock adapter
    src/data/                  ListSource / ListQuery contract, SourceError, preferences (density, page size), Query provider
    src/patterns/              EnterprisePage + ListWorkspace, StatusTabs, DomainStatusBadge catalogue, list URL state,
                               filter-bar/ (FilterBar, FilterToggle, ActiveFilters, SavedViewsMenu, ListSearch),
                               data-table/ (DataTable, Pagination, DensityToggle, ColumnSettings, BulkBar),
                               form/ (EoraForm + bound fields), feedback/ (ConfirmDialog, FeedbackProvider),
                               import-export/ (ExportMenu, ImportWizard), notifications/ (NotificationCenter + mock)
  config/                      tsconfig base
```
Dependency direction: `apps/web → features → ui → design-tokens`. Packages never import from `apps/web`; `ui` never imports `features`.

## 2. Mock-data policy
- Data comes from interfaces (e.g. `NavigationBadgeSource`, later `ControlTowerSource`). Phase implementations are `*.mock.ts` files.
- Every page or panel rendering mock data shows a visible **"Mock data"** label; the app shell shows a persistent prototype notice until real APIs are connected.
- Mock values follow docs 02/14 sample scenarios; they are never presented as operational data, exported or persisted.
- Review pages may simulate adapter responses with `?simulate=slow|empty|error|forbidden|stale` (example list only) so every state can be reviewed and tested; production adapters have no such switch.

## 3. Phase plan (stop for owner review after each)
| Phase | Scope | Exit criteria |
|---|---|---|
| **1 Foundation** | Workspace + tooling; Next.js + TS + Tailwind + tokens + Inter + Lucide; shadcn-pattern primitives; AppShell with dual sidebar (docked ≥ 1280, overlay below), topbar (breadcrumb, responsive search trigger per D-028 + dialog shell, workspace switcher, bell with actionable badge, user menu), mobile navigation sheet; route structure from navigation config with honest "planned" pages; UI kit review page | All gates in §4 green; screenshots at review widths |
| **2 Shared enterprise patterns** | *(owner scope D-036: page template, FilterBar, DataTable, density, status tabs/badges, forms, dialogs/feedback, pagination, states incl. permission denied, import/export shell, notification center, showcase — global search results moved to a later phase)* FilterBar (schema-driven, URL sync, mobile sheet), DataTable (TanStack, density presets per D-029, row menu, drawer/row expansion), Pagination, Form + validation (RHF + Zod), StatusBadge catalogue, Modal/Drawer patterns, Notification Center UI, states | Gates green; component docs |
| **3 PPIC Control Tower** | CT screens per doc 19 with typed mock adapter *(built 2026-10-09: CT-0..CT-10, contracts `apps/web/modules/ppic/contracts.ts`)* | Gates green; business labels reviewed against docs 02/15/19 |
| **4 Production Reconciliation** | Run detail, consumption, outputs, remnant/return, variance, investigation, approval, audit timeline | Gates green |
| **5 Mobile PWA foundation** | Mobile shell, role navigation, My Shift, My Tasks, Scan UI, Production reporting UI, QC UI, Inbox; no offline final posting | Gates green; device validation list updated |

## 4. Quality gates (every phase)
| Gate | Command / method | Pass condition |
|---|---|---|
| Format | `pnpm format:check` (Prettier 3 + Tailwind class sorting) | no diff |
| Typecheck | `pnpm typecheck` (tsc --noEmit per package) | 0 errors |
| Lint | `pnpm lint` (ESLint 9 flat config: next/core-web-vitals, typescript-eslint, jsx-a11y via next) | 0 errors |
| Unit tests | `pnpm test` (Vitest) | all pass |
| Component tests | Vitest + Testing Library (jsdom) incl. axe-core rule checks | all pass, 0 axe violations |
| Accessibility (rendered) | Playwright + axe-core (`wcag2a`, `wcag2aa`, `wcag21aa`, `wcag22aa`) on key routes, desktop + mobile | 0 violations or documented exception |
| Keyboard | Playwright: Tab order, Ctrl/Cmd+K, Esc, focus return, arrow navigation | scripted checks pass |
| Responsive review | Playwright at 1920, 1600, 1440, 1280, 1200, 1024, 768, 390, 375 px (+ 640 px ≈ 200 % zoom of 1280) | no page-level horizontal overflow; required topbar items visible |
| Visual consistency | screenshots compared against prototype v2.1 by reviewer | reviewer sign-off (owner) |
| Production build | `pnpm build` | succeeds |
| Documentation | this file + relevant uiux docs updated | done |

## 5. Conventions
- Server Components by default; `'use client'` only for interactive shell parts. No async client components; props across the boundary are serializable.
- Styling only through Tailwind utilities mapped to tokens; no raw hex in components.
- Icons: named imports from `lucide-react`, 16 px in controls, 20 px in navigation, `aria-hidden` with visible or accessible text.
- Routes: `/{module}/{item}` derived from the navigation config; unknown routes → `notFound()`.
- Preferences (density, collapsed groups) stored per user via a preference adapter (Phase 2); localStorage only as a cached convenience.

## 6. Accessibility release validation (tracked, not yet done)
Automated checks above do not replace these; each must be completed before pilot release (O-021):
- Screen-reader walkthrough: NVDA + Firefox/Chrome (Windows), VoiceOver + Safari (macOS, iOS).
- Real-device touch testing: Android phone, iPhone, line-side tablet; gloves where relevant.
- 200 % and 400 % browser zoom on desktop (automated proxy: 640 px viewport).
- Windows high-contrast / forced-colors mode.
- Reduced-motion preference.
Results are recorded here with date, tester and findings.

## 7. Running locally
```bash
npx pnpm@12.10.1 install      # build scripts of dependencies are denied by default (pnpm-workspace.yaml allowBuilds)
npx pnpm@12.10.1 dev          # http://localhost:3100
npx pnpm@12.10.1 check        # format check + typecheck + lint + unit/component tests
npx pnpm@12.10.1 build && npx pnpm@12.10.1 e2e   # Playwright uses the installed Google Chrome (channel "chrome")
```
Turborepo and Next.js telemetry are disabled in the scripts; Turborepo agent guidance (auto-editing AGENTS.md) is off (`turbo.json` `agentGuidance: false`).
CI: `.github/workflows/ci.yml` runs the same gates (format → typecheck → lint → test → build → E2E with Chrome). It has **not run on GitHub yet** — the repository is not pushed (owner decision).

## 8. Phase log
### Phase 1 — Foundation (2026-10-08) — **approved by owner (D-036)**
| Gate | Result |
|---|---|
| Typecheck | 4/4 packages, 0 errors |
| Lint | 0 errors, 0 warnings (ESLint 9, next/core-web-vitals + typescript + jsx-a11y rules) |
| Unit + component tests | 29/29 pass (Vitest + Testing Library; axe-core rules in jsdom, colour contrast excluded there) |
| E2E accessibility | axe `wcag2a/2aa/21a/21aa/22aa`: 5 routes × desktop + mobile, open overlays (search, workspace, notifications, account) and mobile sheet — 0 violations |
| Keyboard | skip link, Ctrl/Cmd+K, focus return after search and tablet overlay, focus ring on 25 tab stops, mobile sheet focus trap / Esc / backdrop / scroll lock — pass |
| Responsive | 10 widths (1920 → 375, incl. 640 px ≈ 200 % zoom) × 5 routes: no page-level overflow; bell, profile, current crumb, workspace access always present; D-028 search modes verified at 9 width/context cases |
| Stability | full E2E suite (38 tests) passed 3 consecutive runs after fixes |
| Production build | Next 16.4 build succeeds; 113 static pages |
| Visual review | screenshots in `docs/review/phase-1/` for owner sign-off |
Defects found and fixed during gates: colour contrast of small labels on hovered topbar buttons (4.34:1 → `text-ink-2s`); focus not returned after search/overlay opened via shortcut or toggle; missing breadcrumb on `/system/ui-kit`; modal account menu aria-hiding focusable content (now non-modal); wrong module highlighted on non-module pages; React Compiler lint (state set inside effects) resolved without effects.
Not done (by design / tracked): screen-reader and real-device validation (§6); Storybook (proposed with Phase 2 component catalogue).

#### Phase 1 completion items (2026-10-08, after first review stop)
| Item | Result |
|---|---|
| Raw-hex guard | `#fff` in notification badge replaced by token; lint rule now catches 3/4/6/8-digit hex in literals and template strings (verified with a probe file, removed) |
| Prettier | Prettier 3.9.9 + Tailwind plugin; whole workspace formatted; `format:check` part of `pnpm check` and CI |
| Turborepo | `agentGuidance: false` — AGENTS.md no longer modified by turbo runs |
| `EoraStatusTabs` | `packages/features/src/patterns` per EORA-COMPONENT-STANDARDS §3; "View by" label, counts in accessible names (`All, 11`), Radix arrow-key navigation, single tab panel; UI kit demo with Mock data label |
| Media-preference E2E | reduced motion (0 transitions/animations in shell), forced-colors emulation (focus outline ≥ 2 px on first tab stops + axe), landmarks (one banner/main/h1, all navigation labelled) desktop + mobile, StatusTabs keyboard |
| Gates rerun | format ✓ · typecheck 4/4 · lint 0 · unit/component 32/32 · build 113 pages · E2E 43/43 on 4 consecutive runs |
Forced-colors and reduced-motion checks are emulation proxies; real Windows high-contrast and OS-level validation stay in §6.

### Phase 2 — Shared enterprise UI patterns (2026-10-08) — **approved by owner (D-037, baseline `1aac1c4`)**
Scope per D-036. Review pages (not in module navigation): `/system/patterns` (showcase), `/system/examples/raw-material-lots` (enterprise list), `/system/notifications`; bell panel uses the same NotificationCenter.
| Gate | Result |
|---|---|
| Format | Prettier: all matched files use Prettier code style |
| Typecheck | 4/4 packages, 0 errors |
| Lint | 0 errors, 0 warnings |
| Unit + component tests | 56/56 pass (logic: URL state, dependent filters, pagination window, import pre-check, status catalogue, navigation release tags; components: DataTable sort/selection/disabled reasons/density/states/axe, Pagination, FilterBar, ActiveFilters, EoraForm, ConfirmDialog, NotificationCenter) |
| E2E | 63 tests (responsive 10 widths × 8 routes, axe on 8 routes × desktop/mobile + overlays, keyboard, media preferences, 15 Phase 2 pattern tests) — **3 consecutive full runs green** |
| Accessibility (rendered) | axe `wcag2a/2aa/21a/21aa/22aa` 0 violations on list, grade popover, import dialog, phone list, phone filter sheet, bell panel and all review routes |
| Responsive / density | rows 44 / 34 px and headers 38 / 32 px measured; no page-level overflow 1920 → 375; phone cards hide zero buckets; 44 px pager targets on phones |
| Production build | succeeds; 116 routes (example list dynamic for `?simulate`) |
| Visual review | screenshots in `docs/review/phase-2/` |
| CI on GitHub runner | **not validated** — workflow exists, repository not pushed |
Defects found and fixed during gates: server sort cycle (TanStack inferred desc-first from an empty accessor → explicit `sortDescFirst: false`, no removal); focus not returned when drawer / filter sheet / confirmation opened from state (`useReturnFocus`); error summary focus raced RHF's own field focus and a later formState commit; contrast during the loading→enabled colour transition of “Mark all as read”; **hydration regression**: Radix Toast/Tooltip providers above the page boundary changed context during hydration, React client-rendered the streamed page and left a hidden duplicate tree (fixed with a stable feedback store rendered as a sibling and TooltipProvider moved into the session provider; E2E guard added); navigation release tags for Sales Orders / Delivery Schedules / MPS updated to R1 (D-035).
Not done / tracked: screen reader, real devices, Windows High Contrast, 200/400 % zoom (§6, O-021); CI execution on GitHub; Storybook (decided against for now, ADR-0011 Amendment 1); global search results; server-side saved views and preference adapter (localStorage cache only); column visibility not yet persisted.

### Phase 3 — PPIC Control Tower frontend (2026-10-09) — **approved by owner (D-042, baseline `b645c8f`)**; O-021 and GitHub CI remain OPEN
Scope per D-037. Screens (navigation `ppic/*`, rendered through `modules/ppic/registry.tsx`): CT-0 Overview, CT-9 Demand & Fulfillment (+ weekly netting and blanket panels), CT-5 Daily Delivery, CT-8 Shortage & Delivery Risk, CT-1 Raw Material Availability, CT-1 Coil/Sheet Conversion, CT-2 Material at Production, CT-10 Work Order Progress, CT-10 Daily Production Results, CT-3 Pending Quality, CT-4 Daily Finished Goods, CT-7 FG Availability, CT-6 Material Variance. All reuse the Phase 2 patterns; business components listed in EORA-COMPONENT-STANDARDS §11.
| Gate | Result |
|---|---|
| Format | Prettier clean |
| Typecheck | 4/4 packages, 0 errors |
| Lint | 0 errors, 0 warnings |
| Unit + component | 78/78 (incl. 21 calculation tests against doc 02 / doc 14 examples: coil 1,930 est. pcs, unit-less pitch not calculable, GYV 47 / PvS −17 / UMV 7.610 kg, sheet GYV 25 provisional, by-difference never FINAL, partial fulfilment, revision, blanket 8,700 + over-release guard, same-bucket forecast matching, uncertain match, ISO week buckets, daily risk inside a covered week, Pending QC not projected; registry ↔ navigation sync) |
| E2E | 94 tests, **3 consecutive full runs green** after fixes (axe on 12 CT routes × desktop/mobile added to the accessibility suite; 12 new Control Tower tests: KPI definitions, UOM bands, filter → URL → drill-down, chart table view, CT-9 netting/blanket/revision/partial, CT-8 daily risk, conversion, Pending QC vs FG, CT-6 indicators/no total loss, adapter states, phone layouts) |
| Accessibility | axe 0 violations on all 13 CT screens (desktop + mobile), overview, drawers; scans wait for a settled page (refetching tables are intentionally dimmed and marked `aria-busy`) |
| Responsive | no page-level overflow 1920 → 375 on CT-0, CT-9, CT-6 + all review routes |
| Production build | succeeds, 117 routes |
| Visual review | `docs/review/phase-3/` (18 screenshots incl. drawers and filtered overview) |
| CI on GitHub runner | **not validated** (no push) |
Defects found and fixed: chart SVG exposed as `img` with focusable children (axe nested-interactive) → `group`; KPI "Definition & basis" target < 24 px; exception titles repeated raw ISO dates; topbar mock period out of sync with the sample business date; CT-9 axe flake (scan during refetch dimming) → scans wait for `aria-busy` to clear; **shared-pattern race** in `useListUrlState` (rapid tab/sort clicks lost under load because a second `router.replace` was de-duplicated against a pending navigation) → native `history.replaceState` per Next 16 docs, computed from the current URL; regression E2E added.
Not done / tracked: production-vs-calendar date toggle (mock does not model shifts crossing midnight); expected-acceptance projection of Pending QC (needs policy); BOM-quantity-based conversion method (not in approved formulas — only layout/pitch methods with BOM-sourced cavity/parts per blank are shown); server read models, cursor pagination, counts parameter (doc 19 §10 recommendations); O-021 and CI on GitHub.

### M1 F1 — Sign-in and live workspace (2026-10-13, milestone M1 / D-066; owner reviews at the M1 demo)
Scope: `/login`, `/select-tenant`, `/invitations/accept`, `/password/forgot`, `/password/reset` (route group `(auth)`, navy brand panel ≥ 1024 px, navy header on phones); `@eora/features/auth` (same-origin client with Sanctum CSRF, `ApiError` from problem+json, `useMe`, `useSessionActions`, `AuthGate`); workspace guarded by `AuthGate` (no session → `/login?next=…`, no tenant → `/select-tenant`, any API 401 signs the shell out); topbar workspace switcher with live tenant/plant and Sign out; Administration module shown from the `core.users_roles.view` hint. Other modules keep mock data and the prototype role switch.
| Gate | Result |
|---|---|
| Format · typecheck · lint | clean (`pnpm check`) |
| Unit + component | 89/89 (new: `safeNext`, `call`/`ApiError`) |
| E2E (mocked API) | 106/106 twice; existing specs run with a synthetic session fixture (`e2e/fixtures.ts`); new `auth.spec.ts`: redirect with destination, uniform login failure, single-workspace sign-in with CSRF header, no open redirect, workspace picker, uniform forgot answer, invitation token missing/valid, reset breached/expired, plant switch + sign-out, axe + no overflow at 1440/375 |
| Real stack smoke | provision `--deliver=console` → accept invitation → sign in → workspace → plant switch → sign out, against Laravel through the Next.js same-origin proxy |
| Production build | succeeds |
Not done: Administration screens and live inbox (F2); stack Playwright in CI, demo seeder, UAT script (F3); screen-reader and real-device checks (O-021).


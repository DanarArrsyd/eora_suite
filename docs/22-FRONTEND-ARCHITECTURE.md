# 22 — Frontend Architecture & Phased Implementation

**Status:** DECIDED (D-033, ADR-0011) · **Canonical for:** frontend repository structure, package boundaries, phase plan, quality gates, mock-data policy and accessibility validation tracking. Visual rules live in `docs/uiux/` (do not restate them here).

## 1. Repository structure
```
package.json · pnpm-workspace.yaml · turbo.json · eslint.config.mjs · vitest.config.ts
apps/
  web/                         Next.js App Router (UI only — no business logic, no DB access)
    app/                       routes: (workspace)/[module]/[item], system/ui-kit, error/not-found
    e2e/                       Playwright: responsive, accessibility (axe), keyboard
packages/
  design-tokens/               tokens.css (CSS variables) + index.ts (typed exports)
  ui/                          primitives: Button, Input, Select, Field, Badge, StatusBadge, NumericBadge,
                               Card, Tooltip, Popover, DropdownMenu, Modal, Drawer, Tabs, Skeleton, Kbd,
                               EmptyState / ErrorState / LoadingState, VisuallyHidden
  features/                    shell (AppShell, PrimarySidebar, SecondarySidebar, Topbar, Breadcrumb,
                               MobileNavSheet, WorkspaceSwitcher, UserMenu, NotificationBell,
                               GlobalSearch trigger/dialog), PageHeader, navigation config + selectors
                               + mock adapter; later phases add FilterBar, DataTable, Pagination, …
  config/                      tsconfig base
```
Dependency direction: `apps/web → features → ui → design-tokens`. Packages never import from `apps/web`; `ui` never imports `features`.

## 2. Mock-data policy
- Data comes from interfaces (e.g. `NavigationBadgeSource`, later `ControlTowerSource`). Phase implementations are `*.mock.ts` files.
- Every page or panel rendering mock data shows a visible **"Mock data"** label; the app shell shows a persistent prototype notice until real APIs are connected.
- Mock values follow docs 02/14 sample scenarios; they are never presented as operational data, exported or persisted.

## 3. Phase plan (stop for owner review after each)
| Phase | Scope | Exit criteria |
|---|---|---|
| **1 Foundation** | Workspace + tooling; Next.js + TS + Tailwind + tokens + Inter + Lucide; shadcn-pattern primitives; AppShell with dual sidebar (docked ≥ 1280, overlay below), topbar (breadcrumb, responsive search trigger per D-028 + dialog shell, workspace switcher, bell with actionable badge, user menu), mobile navigation sheet; route structure from navigation config with honest "planned" pages; UI kit review page | All gates in §4 green; screenshots at review widths |
| **2 Shared enterprise patterns** | Global search results (debounced, mock adapter, loading/empty/error), FilterBar (schema-driven, URL sync, mobile sheet), DataTable (TanStack, density presets per D-029, row menu, drawer/row expansion), Pagination, Form + validation (RHF + Zod), StatusBadge catalogue, Modal/Drawer patterns, Notification Center UI, states | Gates green; component docs |
| **3 PPIC Control Tower** | CT screens per doc 19 with typed mock adapter | Gates green; business labels reviewed against docs 02/15/19 |
| **4 Production Reconciliation** | Run detail, consumption, outputs, remnant/return, variance, investigation, approval, audit timeline | Gates green |
| **5 Mobile PWA foundation** | Mobile shell, role navigation, My Shift, My Tasks, Scan UI, Production reporting UI, QC UI, Inbox; no offline final posting | Gates green; device validation list updated |

## 4. Quality gates (every phase)
| Gate | Command / method | Pass condition |
|---|---|---|
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

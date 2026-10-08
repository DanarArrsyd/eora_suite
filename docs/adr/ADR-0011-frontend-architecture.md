# ADR-0011 — Frontend architecture and phased implementation

- **Status:** Accepted (D-033) · **Date:** 2026-10-08 · Canonical detail: `docs/22-FRONTEND-ARCHITECTURE.md`

## Context
The owner approved prototype v2.1 as the design baseline (D-027) and authorized production frontend work in five phases with a review stop after each. Backend APIs are not yet contracted; business rules, schema and Laravel logic are out of scope for these phases.

## Decision
1. **Monorepo (pnpm workspaces + Turborepo)** for TypeScript packages; Laravel stays a separate app later (`apps/api`, Composer).
   - `apps/web` — Next.js App Router (TypeScript, React Server Components by default, client components only for interaction).
   - `packages/design-tokens` — CSS variables (single source, mirrors `docs/uiux/EORA-DESIGN-TOKENS.md`) + typed token exports.
   - `packages/ui` — primitives (shadcn/ui pattern on Radix + Tailwind + `class-variance-authority`), owned source, no runtime theme switching.
   - `packages/features` — shared enterprise patterns: app shell, navigation config/selectors, topbar, search, notification center, filter bar, data table (as phases deliver them).
   - `packages/config` — shared TypeScript configuration.
2. **Versions pinned** (verified 2026-10-08): Next 16.4.0, React 19.3.0, TypeScript 5.9.3 (TS 7 rejected: typescript-eslint supports < 6.1), Tailwind CSS 4.3.3, radix-ui 1.7.0, lucide-react 1.53.0, ESLint 9.39.5 + eslint-config-next 16.4.0, Vitest 5.0.3, Testing Library, Playwright 1.64 with axe-core. pnpm 12.10.1 invoked via `npx pnpm@12.10.1` (no global install). Exact versions in lockfile.
3. **Inter via `next/font`**, Lucide via explicit named imports (tree-shaken), Tailwind v4 `@theme` maps semantic tokens to utilities.
4. **Data access through typed mock adapters** (`*.mock.ts`) behind interfaces that later API clients implement. Every mock-driven screen shows a visible "Mock data" label. No mock value is presented as operational data.
5. **Navigation** from one typed config; server-side filtering contract (`GET /api/v1/me/navigation`) is mocked in Phase 1.
6. **Quality gates per phase:** `typecheck`, `lint`, unit + component tests (Vitest/Testing Library, axe-core rules in jsdom), end-to-end responsive + accessibility checks (Playwright + axe-core, Chrome), visual review screenshots, documentation update. A phase that fails a gate does not advance.

## Consequences
+ Shared components enforced by package boundaries; pages compose, never restyle.
+ Mock adapters let UI progress without inventing backend contracts.
− Two runtimes later (Node + PHP); same-origin proxy per ADR-0006 required at integration.
− Node 25 is the local runtime; CI must pin a supported LTS (decided at CI setup).

## Amendment 1 — Phase 2 dependencies and data-pattern rules (2026-10-08, D-036)
Added to `packages/features` (exact pins, each released ≥ 2 weeks before adoption): `@tanstack/react-table` **9.2.4** (v9 is the current stable major; v8 has had no release since 2025-04), `@tanstack/react-query` **5.103.2**, `react-hook-form` **7.88.0**, `zod` **4.6.5**, `@hookform/resolvers` **5.9.1**. `apps/web` pins the same Query and Zod versions. Prettier 3.9.9 (+ Tailwind plugin) and Node 24 LTS (`.nvmrc`, CI) were added at the end of Phase 1.
Rules:
1. Modules describe tables with `EoraColumn<T>` and lists with `ListSource<T>` (`packages/features/src/data`); TanStack Table stays inside `EoraDataTable`, so a library upgrade never touches module code.
2. List state (view, search, filters, sort, page, size) lives in the URL; Query keys contain the full list query; sorting/filtering/paging are server-side (`manual*`).
3. Providers rendered above a page's Suspense boundary must keep a **stable context value during hydration** (stable stores, providers as siblings). A changing provider value made React client-render streamed page segments and left a hidden duplicate tree (`#S:0`) — found and fixed in Phase 2, guarded by an E2E test.
4. Dialogs opened from state restore focus to the invoking element (`useReturnFocus`).
5. No Storybook in Phase 2: the shared reference is the in-app showcase (`/system/patterns`, `/system/examples/raw-material-lots`) running with the real providers. Revisit if external designers need isolated stories.
Consequence update: Node 24 LTS is pinned for CI (supersedes the "decided at CI setup" note above).

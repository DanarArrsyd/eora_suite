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

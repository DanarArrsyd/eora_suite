# EORA Design Tokens — Modern Corporate Blue

**Status:** DECIDED (D-021, ADR-0010 + Amendment 1 / D-023, D-026) · **Canonical for tokens.** Principles: [EORA-DESIGN-CONSTITUTION.md](EORA-DESIGN-CONSTITUTION.md). Prototype source: `design/mockups/assets/eora-tokens.css`. Production: same names as CSS variables in `packages/design-tokens`, exposed to Tailwind via `@theme` and mapped to shadcn variables (`--background`, `--primary`, `--border`, `--ring`, …).

## 1. Owner palette (raw values — use only through semantic tokens)
| Raw token | Hex | Owner role |
|---|---|---|
| `blue-950` | `#172554` | Primary sidebar |
| `white` | `#FFFFFF` | Secondary sidebar, surface |
| `slate-50` | `#F8FAFC` | Workspace background |
| `blue-700` | `#3559C7` | Primary accent |
| `blue-800` | `#2948A8` | Primary hover |
| `blue-50` | `#EFF6FF` | Active menu background |
| `slate-800` | `#1E293B` | Primary text |
| `slate-500` | `#64748B` | Secondary text |
| `slate-400` | `#94A3B8` | Muted text |
| `slate-200` | `#E2E8F0` | Border |
| `green-700` | `#15803D` | Success |
| `amber-700` | `#B45309` | Warning |
| `red-600` | `#DC2626` | Danger |
| `blue-600` | `#2563EB` | Information |

## 2. Semantic tokens
| Token | Value | Use |
|---|---|---|
| `--color-bg-workspace` | slate-50 | Page background |
| `--color-bg-surface` | white | Cards, tables, secondary sidebar, topbar |
| `--color-bg-subtle` | slate-50 | Table header, section footers |
| `--color-bg-hover` | slate-100 `#F1F5F9` | Hover background only |
| `--color-bg-selected` | blue-50 | Active menu item, selected row |
| `--color-bg-sidebar-primary` | blue-950 | Primary sidebar, mobile header |
| `--color-text-primary` | slate-800 | Body, headings, values |
| `--color-text-secondary` | slate-500 | Labels, descriptions, helper text, placeholders |
| `--color-text-secondary-strong` | **slate-600 `#475569` (added, Amendment 1)** | Secondary text on tinted backgrounds: selected rows (`#EFF6FF`, 6.96:1), segmented controls on `#F1F5F9` (6.92:1), command-search sub-lines |
| `--color-text-muted` | slate-400 | **Decorative icons only** (see §4) |
| `--color-text-sidebar` | slate-300 `#CBD5E1` | Inactive primary-sidebar icons |
| `--color-border` | slate-200 | Layout separators (decorative) |
| `--color-border-control` | **slate-450 `#7F8EA3` (added)** | Input/select/checkbox outlines |
| `--color-border-strong` | slate-300 | Secondary buttons |
| `--color-primary` / `-hover` / `-subtle` | blue-700 / blue-800 / blue-50 | Primary action, links, active states |
| `--color-focus-ring` | blue-700 | 2 px focus outline |
| Status solid | success / warning / danger / info | Icons, strokes, chart risk |
| Status badge fg/bg | success `#166534/#DCFCE7` · warning `#92400E/#FEF3C7` · danger `#991B1B/#FEE2E2` · info `#1D4ED8/#DBEAFE` · neutral `#475569/#F1F5F9` | Badges, chips, banners |
| Data-viz | `--viz-1` blue-700, `--viz-2` `#93A8E6`, `--viz-3` slate-400, `--viz-risk` danger | Max 4 series |

## 3. Typography
Font: **Inter** (400/500/600/700), fallback `Arial, system-ui, sans-serif`. Numerals: `font-variant-numeric: tabular-nums` in tables, KPI and quantity fields.

| Role | Size / line | Weight |
|---|---|---|
| Page title | 24 px / 32 (max 28) | 600 |
| Section / card title | 16 px / 24 (max 18) | 600 |
| Body | 14 px / 20 | 400 |
| Sidebar navigation | 13.5 px | 500 (active 600) |
| Data table | 13 px / 18 | 400 |
| Form label | 13 px | 500 |
| Supporting text | 12 px / 16 | 400 |
| KPI value | 28 px / 34 (24–32) | 600 |
| Breadcrumb / "View by" label | 12 px uppercase, +0.05 em | 500–600 |
Monospace (`ui-monospace`) only for location/bin codes and technical IDs where character alignment helps.

## 4. Contrast evaluation (WCAG 2.2, computed 2026-10-08)
| Pair | Ratio | Result | Action |
|---|---|---|---|
| Primary text on white / workspace | 14.63 / 13.98 | AA | — |
| Secondary text on white / workspace | 4.76 / 4.55 | AA (normal text) | Keep. Do **not** place secondary text on slate-100 (4.34 fails) — table headers use slate-50 |
| Muted `#94A3B8` on white | 2.56 | **Fails** text | Restricted to disabled text (exempt) and decorative icons; never informational text or placeholders |
| Accent on white / white on accent | 6.17 | AA | — |
| White on hover `#2948A8` | 8.12 | AAA | — |
| Accent on active menu `#EFF6FF` | 5.67 | AA | — |
| Sidebar icon `#CBD5E1` on `#172554` | 9.90 | AAA | Inactive icon colour (owner muted `#94A3B8` = 5.73 also passes, kept as fallback) |
| Success / warning / danger / info on white | 5.02 / 5.02 / 4.83 / 5.17 | AA | Solid status colours usable as text on white |
| Danger `#DC2626` on `#FEF2F2` | 4.41 | **Fails** | Badges use darker fg tokens (`#991B1B` on `#FEE2E2` = 6.80) |
| Badge fg/bg pairs (§2) | 5.49–6.80 | AA | — |
| Border `#E2E8F0` on white | 1.23 | Decorative only | Inputs need ≥ 3:1 (WCAG 1.4.11) → **added `--color-border-control #7F8EA3` = 3.33** |
Adjustments keep the Modern Corporate Blue identity: no owner colour changed; two tokens added (`--color-border-control`, `--color-text-secondary-strong`) and usage limits set for muted text and light danger backgrounds.

Rendered-page verification (real foreground/background combinations, hover/selected/error/disabled states): see EORA-COMPONENT-STANDARDS.md §8a. Numeric badges: white on `--color-danger` 4.83:1 (11 px semibold).

## 5. Spacing, radius, elevation, layout
- Spacing scale (4 px grid): 4, 8, 12, 16, 20, 24, 32, 40.
- Radius: 4 badges/checkbox · 6 inputs/buttons · 8 cards/menus/panels · 12 mobile cards/sheets. No pill-shaped controls except count pills and avatars.
- Elevation: cards `0 1px 2px` (hierarchy only); popovers/menus/notification panel; drawer. No decorative shadows.
- Layout: primary sidebar 64 px, secondary sidebar 248 px, topbar 56 px, control height 36 px (sm 32), table row 44 px comfortable / 34 px compact (no compound rows), PWA touch target 48 px (minimum 44 px).
- Breakpoints: ≥1280 full dual sidebar; 1024–1279 secondary sidebar becomes overlay; 768–1023 compact topbar; <768 mobile shell (hamburger opens both sidebars as a sheet) — PWA task screens use bottom navigation.

## 6. Motion
120–180 ms ease for hover, menu and drawer; respects `prefers-reduced-motion`; no looping or decorative animation. Skeleton shimmer only while loading.

## 7. Implementation mapping (Phase 1, ADR-0011)
Canonical variables live in `packages/design-tokens/src/tokens.css` (same names as §2). Tailwind utilities use short aliases defined in `apps/web/app/globals.css` (`@theme inline`) to avoid circular names:
| Utility family | Maps to |
|---|---|
| `bg-workspace`, `bg-surface`, `bg-subtle`, `bg-hover`, `bg-selected`, `bg-sidebar`, `bg-overlay` | `--color-bg-*` |
| `text-ink`, `text-ink-2`, `text-ink-2s`, `text-ink-muted`, `text-link`, `text-on-accent`, `text-sidebar-ink` | `--color-text-*` |
| `border-line`, `border-line-control`, `border-line-strong`, `outline-ring` | `--color-border*`, `--color-focus-ring` |
| `bg-accent`, `bg-accent-hover`, `bg-accent-subtle` | `--color-primary*` |
| `*-stat-ok`, `*-stat-warn`, `*-stat-err`, `*-stat-info`, `*-stat-neutral` (`-fg`, `-bg`) | status tokens |
Breakpoints: `md` 768, `lg` 1024, `wide` 1200, `xl` 1280, `xxl` 1440, `3xl` 1600, `4xl` 1680. Raw hex in components is blocked by lint (`no-restricted-syntax`).

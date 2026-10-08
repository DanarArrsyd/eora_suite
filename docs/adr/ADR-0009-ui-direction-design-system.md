# ADR-0009 — Hybrid UI direction and design system

- **Status:** **Superseded by [ADR-0010](ADR-0010-modern-corporate-ui.md)** (owner rejected this direction on 2026-10-08, D-021) · Originally accepted as direction (D-018) · **Date:** 2026-10-08

> Kept for history. Prototypes archived in `design/mockups/_superseded-v1-ledger-ink/`. Do not implement anything from this ADR except what ADR-0010 explicitly keeps.

## Context
Three directions were proposed in the audit: 1 "Ledger Ink" (document-centric desktop), 2 "Andon Signal" (floor/high-contrast), 3 "Traceline" (flow/genealogy). Owner selected a hybrid.

## Decision
| Surface | Direction | Applies to |
|---|---|---|
| Desktop workspace | **1 — Ledger Ink** | PPIC, Materials, Inventory, Quality supervisor, Delivery, Admin |
| Floor Mode (PWA, tablet/phone) | **2 — Andon Signal** | Operator, Production leader, QC inspector, FG warehouse picker, scanner flows |
| Cross-cutting component | **3 — `MaterialFlowStrip`** | Control Tower, WO detail, lot trace, mobile stepper |

### Design reasoning (why these choices)
- **Ledger Ink:** EORA's core promise is that every quantity is explainable. The visual language borrows from stock cards and ledgers: hairline rules instead of floating cards, quantities in tabular figures with units always printed, and a **conservation line** (`Issued = Consumed + At Production + Returned`) on documents. Prints well in grayscale (doc 08) because it is already rule-and-type based.
- **Andon Signal:** floor users read from distance, with gloves, under mixed light. Large numerals, 56 px minimum touch targets, high contrast, and colour reserved strictly for state (with shape/text redundancy). Light concrete theme by default; graphite theme optional per device — dark is a choice for line-side screens, not a default.
- **FlowStrip:** teaches the no-WIP model directly: quantity by stage (`RM → At Production → Pending QC → QC Released → FG → Shipped`), each stage clickable to filter the table beneath. Always shows `as_of` to avoid being read as a live balance.

### Tokens (initial, subject to brand input O-010)
| Token | Ledger Ink (desktop) | Andon Signal (floor) |
|---|---|---|
| `--surface` | `#F6F4EF` paper | `#E9EAE6` concrete (light) / `#16181B` graphite (dark) |
| `--ink` | `#1C2733` | `#111316` / `#F2F2EF` |
| `--rule` | `#D8D3C8` | `#B9BBB5` / `#2C3035` |
| `--accent` (exception/primary action only) | `#B4441C` oxide | `#B4441C` oxide |
| state OK / WARN / STOP | `#2F6B3F` / `#9A6A00` / `#A3231B` + glyph | same hues, larger fills + glyph |
| Display/label font | IBM Plex Sans Condensed — dense labels without truncation in tables | Barlow Semi Condensed — legible at distance, wide numerals |
| Numerals | IBM Plex Mono, tabular — column alignment of quantities | Barlow tabular figures |
| Radius | 2 px controls, 0 tables | 6 px controls (touch affordance) |
| Shadow | none except overlays (dialogs, popovers) | none except bottom sheet |
| Motion | state feedback only (≤150 ms), respects `prefers-reduced-motion` | same; no loops |

### Component inventory (`packages/ui`, `packages/features`)
Existing (doc 06): `EoraAppShell, EoraPageHeader, EoraFilterBar, EoraDataTable, EoraDetailHeader, EoraTransactionForm (alias EoraForm in CLAUDE.md), EoraStatusBadge, EoraConfirmDialog, EoraApprovalPanel, EoraAuditTimeline, EoraImportWizard, EoraReportViewer, EoraNotificationCenter, EoraMobileTaskView, EoraScannerField`.
Added: `QuantityWithUom` (never a bare number), `ConversionBadge` (Calculated / Provisional / Not calculable + missing inputs), `StockStatusChip` (text + glyph, never colour alone), `MaterialFlowStrip`, `ConservationLine`, `VarianceIndicator` (value + status + basis tooltip), `AsOfStamp`, `ExceptionQueue`, `FloorNumpad`, `MeasureCapture` (scale/counter evidence).
Rules: headless table core (TanStack) composed with slots; no component exceeds one responsibility; Storybook story per state (empty, loading, partial, error, offline, unauthorized, conflict, period-locked).

### Accessibility and responsiveness
WCAG 2.2 AA target; full keyboard on desktop (grid navigation, `/` focuses filter, `g` shortcuts documented); focus visible 2 px ink outline; status never colour-only; touch targets ≥ 44 px desktop-responsive, ≥ 56 px Floor Mode; breakpoints: ≥1280 workspace, 768–1279 tablet (Floor Mode or condensed workspace), <768 phone (Floor Mode / task view only — no wide grids).

## Consequences
+ Distinct, non-generic, task-fit identity; one token system with two densities.
− Two typographic families to maintain; FlowStrip needs user testing (risk R-UX-02).

## Verification
Mockups in `design/mockups/` reviewed by owner; final approval recorded in doc 13 before any `apps/web` work.

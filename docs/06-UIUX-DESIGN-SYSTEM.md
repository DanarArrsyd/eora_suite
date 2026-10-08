# 06 — UX, Design System & Consistent Page Templates

## Product UX
Original modern enterprise operations workspace; conceptually informed by enterprise software, never copy proprietary SAP/Oracle visuals. Prioritize information density, fast task completion, consistent terminology, accessible contrast and keyboard usage. Desktop for planning/control, PWA for scan/input/approve.

## Design tokens and shared packages
`@eora/ui` visual primitives; `@eora/features` reusable domain-neutral workflows; `@eora/design-tokens`; `@eora/api-client`; `@eora/config`. Centralized colors, typography, spacing, responsive breakpoints, semantic status styles, icons and error messages. Storybook documentation and visual regression tests.

## Standard components
`EoraAppShell`, `EoraPageHeader`, `EoraFilterBar`, `EoraDataTable`, `EoraDetailHeader`, `EoraTransactionForm`, `EoraStatusBadge`, `EoraConfirmDialog`, `EoraApprovalPanel`, `EoraAuditTimeline`, `EoraImportWizard`, `EoraReportViewer`, `EoraNotificationCenter`, `EoraMobileTaskView`, `EoraScannerField`.

## Filter contract
Schema-driven fields (`plant`, `warehouse`, `part`, `customer`, `date_range`, `shift`, `status`, `lot`, `WO`), server-side validation and permissions, shared presets, URL-synced state, reset/save/share, consistent count, sorting, pagination, column settings and export with **identical server query scope**. No bespoke filter UI per page; specialized fields compose from same primitives.

## Template taxonomy
List workspace; transaction detail; master data form; planning board; mobile task/scan; approval inbox; exception investigation; report preview. Distinguish error, empty, loading, partial, offline, unauthorized, expired session and conflicting edits.

## Mockup gate
Produce **3 clearly distinct** design directions with desktop PPIC Control Tower, Material Reconciliation, Production run, Quality queue, FG/Delivery, mobile operator task, mobile QC, notification bell and import/export flows. Include light/dark options, responsive constraints, accessibility, visual rationale and tradeoffs. **Wait for explicit selection before coding UI.**

## UX acceptance
A role can finish top tasks without unnecessary cross-module navigation; errors identify exact field and recovery; no duplicate action on double click; timestamps and units visible; filters consistent; keyboard navigation and mobile touch targets tested with users.

## Mandatory frontend skill and visual quality gate
Before UI/UX analysis, three distinct mockup proposals, design tokens, component work, accessibility review or frontend coding, discover and read the installed **Frontend / Front-End / Frontend Design** skill(s) and **Caveman** skill. Document what was actually read. If unavailable, disclose it and request the correct path/installation instead of pretending to have used them. Reject generic AI-slop aesthetics and unverified screenshots; design original, dense-but-legible enterprise workspaces and mobile task flows. No frontend implementation before explicit owner approval.

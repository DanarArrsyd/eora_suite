# 19 — PPIC Inventory & Fulfillment Control Tower — Functional Specification

**Decision basis:** D-004, D-012, D-014, D-018, ADR-0005, ADR-0008. The Control Tower is a **read workspace** inside Planning. It owns no stock and writes nothing except saved views, case assignment shortcuts (calling Reconciliation services) and exports. Every number drills to source documents.

## 1. Users and the decision each screen supports
| Screen | Primary user | Decision it supports |
|---|---|---|
| CT-0 Overview | PPIC | What needs action now to protect the next deliveries? |
| CT-1 RM Coil/Sheet & Estimated PCS | PPIC, Materials | Is there enough material for planned WOs, and how certain is the estimate? |
| CT-2 Material at Production | PPIC, Production Leader | What material is sitting at work centers, for which WO, how long? |
| CT-3 Pending QC & Hold | PPIC, QC Supervisor | Which output is blocked from FG, and how old is it? |
| CT-4 Daily FG Receipt | PPIC, FG Warehouse | How much FG became available per day/shift vs plan? |
| CT-5 Daily Delivery & Outstanding | PPIC, Delivery | What shipped, what is outstanding, what is at risk? |
| CT-6 Material Variance (Loss Investigation) | PPIC, Supervisor, Inventory Controller | Which runs/WOs/lots show unexplained variance and what is their investigation status? |
| CT-7 FG Available / Reserved | PPIC, Delivery | What FG is free vs allocated? |
| CT-8 Shortage & Delivery Risk | PPIC | Which demand lines will miss due date, and why? |
| CT-9 Demand & Fulfillment *(added 2026-10-09, D-034..D-041)* | PPIC, Sales admin | Which customer requirements exist (Customer PO → SO line → schedule lines / releases), what is delivered, outstanding, unscheduled on blankets, and how demand nets per week? |
| CT-10 Production Monitoring *(added 2026-10-09)* | PPIC, Production Leader | Are work orders progressing to plan, and is consumption measured? |

Naming rule (BR-REC-02, D-024): the screen is labelled **"Material Variance"** with sub-label *Loss Investigation*. "Actual Material Loss" appears only for cases resolved `ACTUAL_MATERIAL_LOSS_CONFIRMED`; "Physical Stock Discrepancy" only for approved stock-count results (`PHYSICAL_DISCREPANCY_CONFIRMED`). CT-6 shows confirmed loss and confirmed discrepancy as separate columns from unexplained variance.

## 2. Common behaviour
- **Global context bar:** tenant (fixed by session), plant, date range, production date vs calendar date toggle, shift, timezone shown (e.g. `WIB`).
- **Filters** (schema from QueryDefinition, URL-synced): plant, warehouse, work center, customer, part/item, material grade, lot, WO, date range, shift, status. Saved views per user; shared views per role.
- **`MaterialFlowStrip`** on CT-0, CT-2, CT-3, CT-4: stages `RM Unrestricted → At Production → Pending QC → QC Released → FG Unrestricted → Shipped (period)`. Each stage: quantity with UOM, `as_of`. Stages with mixed UOM (kg vs pcs) never sum across the UOM boundary; the strip shows a visible boundary marker at the transformation step.
- **Freshness:** live ledger queries for single-entity detail; read models for aggregates with `as_of` and lag badge (`Updated 09:40 WIB · 2 min`). Lag > tenant threshold (default 10 min) shows warning state.
- **States:** loading (table skeleton with column headers), empty-first-run ("No material lots received yet. Post an RM receipt to see conversion estimates."), filtered-empty (shows active filters + clear action), error (what failed + retry + correlation ID), unauthorized (no data leak, request access path), stale read model, offline (desktop shows banner; data marked as last loaded).
- **Export:** XLSX/PDF via ADR-0008 with identical scope; footer per doc 08.
- **Permissions:** view requires `planning.control_tower.view` plus module read scope for each widget; widgets without scope render a locked state, not zero.

## 3. Screen specifications

### CT-0 Overview
Components (updated D-021, see uiux/EORA-PAGE-TEMPLATES.md §4): filter bar → **max 4 decision KPI cards** (each with definition line and drill link; no invented deltas) → `MaterialFlowStrip` (UOM-grouped bands) → exception queue + one question-titled chart (due vs projected supply) → tabbed detail (Physical material, Estimated conversion, Production output, Quality status, FG stock, Shipments). ~~No standalone KPI card row.~~ (superseded by owner request for structured KPI cards, limited to decision KPIs).
Exception types (ordered by impact: due date proximity × quantity):
| Exception | Source | Rule |
|---|---|---|
| Delivery at risk | CT-8 | projected available FG < outstanding before due date |
| Material shortage for released WO | CT-1 | allocated + available RM < WO requirement (in material UOM) |
| Conversion not calculable for planned material | CT-1 | CP missing/unvalidated |
| Pending QC aging over threshold | CT-3 | age > tenant SLA (default 24 h) |
| Variance case open / breach | CT-6 | FINAL breach or UNMEASURED consumption |
| Material idle at production | CT-2 | at work center > N hours without run (default 48 h) |
| Read model stale | system | lag > threshold |

### CT-1 RM Coil/Sheet Availability & Estimated PCS
Grain: lot × location × status. Columns: material grade, item, lot, form (COIL/SHEET), location, status, on-hand (kg or sheets), allocated, available, measured properties source (mill cert / nominal), **CP version + status**, estimated pcs (range), conversion basis, WO allocations.
Formulas (doc 02, ADR-0005; planning estimate, not stock):
- Sheet: `est_pcs = floor(available_sheets_eligible × blanks_per_sheet × parts_per_blank)`; range lower bound applies `SY`.
- Coil: `L_mm = net_usable_kg × 10^6 / (ρ × t × w)`; `strokes = floor((L_mm − head_mm − tail_mm) / pitch_mm)`; `est_pcs = strokes × cavity`; lower bound applies `SY`.
- Uses lot actual properties when present, else item nominal (badge shows which).
- `ConversionBadge`: `Calculated` (all inputs validated) · `Provisional` (nominal inputs or unvalidated CP) · `Not calculable — missing: …`.
Rules: est pcs from alternative parts for the same lot are shown per part and **never summed**; totals row sums only kg/sheets.
Drill: lot → receipt → movements → allocations → WOs.

### CT-2 Material at Production / Remaining
Grain: work center × lot. Columns: work center, lot, item, qty on-hand at MAT_AT_PROD (UOM), custody status (accepted / in transit), WO allocations, last movement, idle hours, last measurement (method, time). Actions (permission-gated, open target screens): request return, open run.
Shows `ConservationLine` per lot: `Issued = Consumed + At Production + Returned + Split-to-remnant` for the selected period.

### CT-3 Pending QC & Hold
Grain: inspection lot. Columns: part, output lot, WO/run, production date/shift, qty PENDING_QC, HOLD, REWORK, REJECTED, age (h), inspector assigned, due by. Aging buckets: <8 h, 8–24 h, 24–72 h, >72 h. Partial dispositions visible. Rule: none of these quantities appear in FG availability.

### CT-4 Daily FG Receipt
Grain: production date × shift × part (toggle calendar date). Columns: planned output (from WO schedule), good output posted, QC released, **FG received**, received vs plan, released-not-received (QC_RELEASED balance). Definitions: FG received = Σ FG_RECEIPT legs into FG_WAREHOUSE in period. Day boundary from shift calendar (DD-08).

### CT-5 Daily Delivery & Outstanding
Grain: date × customer × part × PO line/release. Columns: due qty, shipped qty (posted shipments), delivered (POD), outstanding, FG available, allocated, gap, risk flag. Outstanding = schedule-line required qty − Σ shipped (BR-DEM-10; grain is the Delivery Schedule Line of an SO line, D-034). Partial shipments listed under the line (detail drawer). Daily dates are never collapsed into weeks (D-041).

### CT-6 Material Variance (Loss Investigation)
Grain: run, with roll-up to WO and input lot. Columns:
| Column | Unit | Notes |
|---|---|---|
| Consumed `C` | kg/sheet | measurement method badge; `UNMEASURED` highlighted |
| Theoretical `T` | pcs | CP version |
| Good / NG Rework / NG Rejected / Other | pcs | |
| **GYV** | pcs, % | status chip NOT_CALCULABLE / PROVISIONAL / FINAL |
| **PvS** | pcs, % | KPI; not a case trigger by default |
| Scrap weighed `S` | kg | |
| **UMV** | kg, % | status chip |
| Tolerance / breach | — | applied rule level (tenant/plant/item/operation) |
| Case | id, status, owner, age | |
Filters add: indicator status, breach only, case status, cause code. Drill: run → Production Posting legs → measurements/evidence → genealogy → case timeline. Summary band shows counts of FINAL vs PROVISIONAL vs NOT_CALCULABLE (so users see how much is still unproven) — **not** a total "loss" figure.

### CT-7 FG Available / Reserved
Grain: part × FG location × lot. On-hand UNRESTRICTED, allocated (by delivery line), available, QC_RELEASED-not-received shown separately as "incoming from QC".

### CT-8 Shortage & Delivery Risk
Grain: Delivery Schedule Line by due date (D-034). Projection: `available FG + QC_RELEASED + planned output of released WOs before due date − allocations − earlier demand`; `PENDING_QC × expected acceptance` is **not projected in R1** because no expected-acceptance policy is approved — Pending QC is shown as its own column only (Phase 3 decision recorded here; owner may approve a policy later). Risk is evaluated per due date even when the weekly bucket is covered (D-041, doc 14 #48). Each contribution shown as its own column; risk reason codes: `NO_WO`, `MATERIAL_SHORT`, `QC_BACKLOG`, `CAPACITY` (V1 basic), `NOT_CALCULABLE_CONVERSION`. Netting per BR-DEM-03.

## 4. Data sources and refresh
| Read model | Updated by | Max lag target |
|---|---|---|
| `rm_conversion_view` | consumers of receipt/issue/return/split/CP-change events | 2 min |
| `flow_strip_snapshot` | all posting events | 2 min |
| `pending_qc_aging` | output/disposition events + 5-min clock | 5 min |
| `daily_fg_receipt`, `daily_delivery_outstanding` | FG receipt / shipment / PO events | 2 min |
| `material_variance_summary` | variance_results inserts | 2 min |
Rebuild command per read model from ledger; nightly verification against ledger.

## 5. Performance (see doc 20)
Overview p95 < 1.0 s with read models at baseline load; CT-6 query p95 < 1.5 s for 90 days of one plant; exports async.

## 6. Acceptance (additions to doc 14)
- CT-A1: Est pcs for a coil with pitch lacking unit shows `Not calculable — missing: pitch unit` (doc 14 #5).
- CT-A2: Pending QC 200 pcs never contributes to FG available in CT-7 or CT-8 available column.
- CT-A3: QC released 300, FG received 0 → CT-4 shows 300 released-not-received, FG received 0 (doc 14 #7).
- CT-A4: Variance from by-difference consumption shows PROVISIONAL and a linked case; no "loss" wording.
- CT-A5: Grid and export of CT-5 with the same filter produce identical rows (doc 14 #13).
- CT-A6: User scoped to Plant A sees no Plant B rows in any widget, strip or export.

## 7. CT-9 Demand & Fulfillment (added 2026-10-09)
Grain: Delivery Schedule Line (D-034). Columns: schedule line + type + revision, due date (daily) + weekly bucket, part/customer, SO line and source Customer PO line, required, delivered, outstanding, FG allocated, status / demand exception. Views: All, Outstanding, Due ≤ 7 days, Partially fulfilled, Blanket releases, Exceptions, Fulfilled & superseded (history kept, D-038).
Panels sharing the filter scope: **Weekly demand (MPS netting bucket)** — firm required, firm outstanding, forecast, net forecast, outstanding demand, forecast match status per customer × part × plant × week (BR-DEM-07/08/13, D-040/D-041); forecast without customer shows *Needs review — not netted*. **Blanket orders** — Blanket Order Qty, Released, Delivered, Remaining Scheduled, Unscheduled Blanket Balance (BR-DEM-14, D-039). The net production requirement (BR-PLN-05) is **not** shown while PROPOSED.

## 8. CT-10 Production Monitoring (added 2026-10-09)
Work Order Progress (grain WO): good of planned, NG reworkable, NG rejected, weighed process scrap (kg), actual consumed (material UOM) + measured flag, material-short flag. Daily Production Results (grain production date × shift × run): good, NG, scrap, consumed with measurement method; by-difference consumption flagged. Good output is not FG.

## 9. Overview KPI definitions (CT-0, implemented Phase 3)
| KPI | Value / UOM | Definition | Source domain | Basis | Meaning |
|---|---|---|---|---|---|
| Demand lines at delivery risk · next 7 days | lines + gap pcs | schedule lines due ≤ 7 days with projected supply < outstanding | Demand · Inventory · Planning (CT-8) | CT-8 projection (Pending QC excluded) | uncovered pieces; not a material shortage or failure yet |
| Released WOs short of material | WOs | allocated + available RM < WO requirement | Planning · Materials (CT-1) | material UOM, never estimated pcs | material must be allocated/received/substituted |
| Pending QC over 24 h SLA | lots + pcs | PENDING_QC older than tenant SLA (default 24 h) | Quality (CT-3) | age since output posting | not FG available until QC release + FG receipt |
| Open material variance cases | cases | cases not closed (split FINAL breach vs provisional/unmeasured) | Reconciliation (CT-6) | ADR-0005 tolerance on FINAL / unmeasured consumption | investigation, not confirmed loss |
Every card shows as-of and drills to its source list. No deltas or trends are shown (none are defined).
Flow strip bands: physical material (kg / sheet, separate lines) | transformation boundary | Pending QC → QC released not received → FG available → Shipped this week (pcs). Chart: "Will FG supply cover customer dues each day?" per part (pieces only comparable within a part), bars = outstanding due, line = projected supply, risk labels, table view; palette validated (tokens doc).
Detail tables are on their own server-paged screens; the overview never loads all transactions (performance rule, doc 20).

## 10. Data contracts (typed mock, Phase 3)
Contracts: `apps/web/modules/ppic/contracts.ts`. Each list screen = one QueryDefinition resource (`ct.demand-lines`, `ct.weekly-demand`, `ct.blanket-orders`, `ct.daily-delivery`, `ct.delivery-risk`, `ct.rm-lots`, `ct.conversion`, `ct.material-at-production`, `ct.wo-progress`, `ct.daily-production`, `ct.pending-qc`, `ct.daily-fg-receipt`, `ct.fg-availability`, `ct.material-variance`) consumed through `ListSource<T>` (`ListQuery` → rows + `ListMeta {total, asOf, timezone}`); the overview is the single-row resource `ct.overview` (ADR-0008 Amendment 1). Rows carry server-computed business values (outstanding, estimates, indicators, risk) and their own UOM; the UI formats only. Mock adapters (`modules/ppic/mock/*`) simulate filtering, sorting, paging, view counts, latency, stale/forbidden/error states, and compute values with reference implementations of the approved formulas (`calc.ts`, unit-tested against doc 02/14 examples).
Open contract questions (recommendations): (1) `view` counts — return them from the same QueryDefinition (`GET …?counts=views`) to avoid an extra endpoint; (2) cursor pagination for CT-6 / daily screens when history grows (ADR-0008 §4); (3) the overview exception queue should be a server read model (`ct_exception_queue`) ranked server-side; (4) expected-acceptance policy for Pending QC in CT-8 needs an owner decision before it can be projected.


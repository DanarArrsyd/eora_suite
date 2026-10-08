# 02 — Material Conversion, Yield, Loss & Reconciliation

## Hard rules
**Material issued ≠ material consumed.** Coil kg/sheet count and output pcs are different measures. Theoretical output is a planning estimate, **not** an inventory movement. Do not infer physical theft/shortage from yield variance. Always capture lot, WO/run, machine/dies, process, shift, operator, calculation version and evidence.

## Material physical balance (native UOM)
For a controlled lot/production location (work center) and cut-off: `opening_at_production + issues_in + custody_accepted_in - actual_consumed - returns_to_warehouse - transfers_out = expected_closing_at_production` (native material UOM). Remnant lot split moves quantity between parent and child lot **inside** the location and does not change the location total. Reconcile `expected_closing` to physical counted closing quantity.

**Scrap semantics (DECIDED D-011, ADR-0004):** process scrap is **not** a deduction from Material at Production. Input leaves Material at Production once, as `actual_consumed`; process scrap (kg, weighed) is an **output** of the transformation, alongside Good, NG Reworkable and NG Rejected. Reusable remnant is unconsumed material (lot split); material return is a transfer back to RM. Therefore nothing is subtracted twice.

**Consumption measurement (DECIDED D-010, ADR-0003):** `actual_consumed` must be backed by independent measurement (weighed remaining, counted remaining, weighed consumed, measured length). By-difference consumption is `UNMEASURED/PROVISIONAL` and opens an investigation case. Model issued stock transfer and consumed stock depletion separately. For multi-step processes, each transfer and output is an explicit transaction.

## Theoretical pcs conversion
- **Sheet:** `eligible_sheets × validated_usable_blanks_per_sheet × parts_per_blank × expected_process_yield` where layout, nesting, orientation, blank dimensions, cavity and setup sheets determine eligible quantity. Distinguish theoretical gross strokes, expected good and standard loss.
- **Coil:** estimate usable length from **net usable mass / (density × thickness × width)** using consistent units and measured properties; estimate strokes from effective usable length / pitch; multiply by validated parts-per-stroke/cavity and account for head/tail, setup and process allowances. Actual coil length may be measured directly; prefer traceable measurements. `pitch=0.32` alone is insufficient to convert 700 kg to 2,187/2,188 pcs. Require units (mm/m), width, thickness, density, cavities, layout and net usable mass.
- **Units:** formula inputs carry explicit units (`mm`, `g/cm³`, `kg`); unit-less or mismatched inputs make the result `NOT_CALCULABLE`. Lot actual properties (mill certificate, measured) take precedence over item nominal values; the basis is displayed.
- **Rounding:** floor for countable theoretical whole units unless approved engineering policy specifies another method; store unrounded values and formula version, show estimated ranges when inputs uncertain. Never silently round stock movements. Full rounding table in ADR-0005.
- **Shared material:** one coil can feed multiple parts/WO; allocate consumed quantity once, prevent summing alternative theoretical outputs as independent inventory.

## Distinct reconciliation metrics
*Revised 2026-10-08 per D-012; normative definitions, rounding, tolerance and finality in `adr/ADR-0005-variance-indicators.md`.*

Production variance indicators (per run, rolled up to WO and input lot):
- **I-1 Gross Yield Variance (GYV, pcs):** `T − (G + NR + NJ + O)`, where `T = floor(theoretical gross pcs from measured consumed C via validated conversion profile)`. NG counts as accounted output. Replaces former "unexplained output-equivalent variance".
- **I-2 Performance vs Standard (PvS, pcs):** `G − floor(T × SY)`. Standard yield `SY` already includes expected NG/process loss, so NG is not subtracted again. KPI only. Replaces former "yield variance vs process standards".
- **I-3 Unexplained Material Variance (UMV, kg):** `Ckg − ((G + NR + NJ + O) × NW + S + D)`; mass balance using weighed scrap `S` and validated net weight per piece `NW`.
Each indicator has status `NOT_CALCULABLE → PROVISIONAL → FINAL`; tolerance breach is evaluated on FINAL values (abs AND %).

Other metrics (unchanged in meaning):
1. **Physical material discrepancy:** counted physical quantity minus expected ledger balance in material UOM (kg/sheets); subject to stock count investigation. Only this metric, after approval, may become a **Physical Stock Discrepancy**.
   - **Actual Material Loss** (D-024) is a separate, investigation-proven outcome (evidence + independent approval + adjustment with loss reason code). Unexplained variance alone never becomes loss.
2. **Process loss (documented):** measured trim/skeleton, setup, head/tail scrap with reason codes; documented outputs, not unexplained.
3. **FG reconciliation:** QC accepted, FG received, reserved, shipped and returned are separate stages; never infer them from good production output alone.

## Worked scenarios
**Sheet A:** 80 sheets × 10 pcs = 800 theoretical; 760 good + 5 NG = 765 accounted pcs; **35 pcs preliminary variance** *only if all 80 sheets were actually consumed, no other outputs/remnants/scrap and standard is valid*. If 3 sheets remain unconsumed, recalculate on 77 sheets: 770 theoretical → 5 pcs provisional variance (assuming no other outputs). Record 3 sheets as Material at Production, not consumed.

**Sheet B:** 80 sheets issued, 1 sheet counted remaining and returned, 79 consumed sheets × 10 = 790 theoretical; 760 good + 5 NG + 0 documented other = **GYV 25 pcs (3.16%)**, provisional until finality conditions are met, then investigated if tolerance is exceeded. The 25 pcs is **not confirmed physical loss**.

**Coil (corrected 2026-10-08):** the earlier figure "2,187/2,188 pcs" equals `700 ÷ 0.32`, i.e. kg divided by pitch, which is **dimensionally invalid** and must never be produced. With only `700 kg` and `pitch = 0.32` (no unit, no geometry) the system shows `Not calculable — missing: pitch unit, thickness, width, density, cavity, head/tail allowance`.
Illustrative fully specified example (sample values, not customer data): SPCC density 7.85 g/cm³, thickness 1.2 mm, width 120 mm, pitch 320 mm, 1 cavity, head+tail 1,500 mm.
- Planning estimate for 700 kg issued: `L = 700×10^6 / (7.85×1.2×120) = 619,249.8 mm`; `strokes = floor((619,249.8 − 1,500)/320) = 1,930`; **est. 1,930 pcs** (planning only, not stock).
- Actual: weighed remaining 87.600 kg → consumed `C = 612.400 kg` → `L = 541,755.1 mm` → `T = 1,688 pcs`. Reported good 1,620, NG reworkable 12, NG rejected 9 → **GYV = 1,688 − 1,641 = 47 pcs (2.78%)**.
- With `SY = 0.97`: expected good `floor(1,688 × 0.97) = 1,637` → **PvS = −17 pcs**.
- With `NW = 0.290 kg/pc` and weighed scrap 128.900 kg: **UMV = 612.400 − (1,641 × 0.290 + 128.900) = 7.610 kg (1.24%)**.
- The 87.600 kg remaining stays Material at Production (or is returned/split as remnant); it is never variance. Do **not** label any difference as lost without investigation.

## Investigation workflow
`Draft/Provisional → Reconciliation Required → Investigation Open → Evidence Collected → Reviewed → Approved Resolution → Closed` (with reopen/reversal policies). Thresholds configurable by tenant/plant/item/process and based on both absolute and relative variance; critical exceptions may block WO final close. Maker-checker for stock adjustments. Store cause code, owner, due date, attachments, measured quantities, before/after, approvals and ledger reference.

## Dashboard KPIs
Issued RM, material at production, actual consumed, remnant, return, documented scrap, theoretical pcs, good, NG reworkable, NG rejected, weighed scrap, Pending QC, QC accepted, FG received, GYV/PvS/UMV with status (not calculable/provisional/final), confirmed physical discrepancy (stock count only), case aging and resolution. Drill-down: part → WO/run → lot → issue → consumption → output → QC → case.

## Required tests
Sheet 80/800/760/5=35 provisional; sheet 79/790/760/5=25; coil sample GYV 47 / PvS −17 / UMV 7.610 kg; 700 kg ÷ 0.32 rejected as dimensionally invalid; by-difference consumption opens case and blocks FINAL; issued vs consumed difference; remaining material; multi-WO coil allocation; unit mismatch; pitch without units; over-reporting; zero/negative inputs; stale conversion version; partial QC; duplicate posting; cross-tenant isolation; close blocking and reversal.

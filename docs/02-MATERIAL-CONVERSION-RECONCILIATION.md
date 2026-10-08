# 02 — Material Conversion, Yield, Loss & Reconciliation

## Hard rules
**Material issued ≠ material consumed.** Coil kg/sheet count and output pcs are different measures. Theoretical output is a planning estimate, **not** an inventory movement. Do not infer physical theft/shortage from yield variance. Always capture lot, WO/run, machine/dies, process, shift, operator, calculation version and evidence.

## Material physical balance (native UOM)
For a controlled lot/production location and cut-off: `opening_at_production + receipts/issues_in + returns_in - actual_consumed - returns_to_warehouse - transfers_out - physical_scrap_out = expected_closing_at_production` (all normalized to one material UOM with measured conversions). Reconcile this to physical counted closing quantity. Define exact semantics of scrap: whether included in consumed or posted separately; **never subtract twice**. Model issued stock transfer and consumed stock depletion separately. For multi-step processes, each transfer and output is an explicit transaction.

## Theoretical pcs conversion
- **Sheet:** `eligible_sheets × validated_usable_blanks_per_sheet × parts_per_blank × expected_process_yield` where layout, nesting, orientation, blank dimensions, cavity and setup sheets determine eligible quantity. Distinguish theoretical gross strokes, expected good and standard loss.
- **Coil:** estimate usable length from **net usable mass / (density × thickness × width)** using consistent units and measured properties; estimate strokes from effective usable length / pitch; multiply by validated parts-per-stroke/cavity and account for head/tail, setup and process allowances. Actual coil length may be measured directly; prefer traceable measurements. `pitch=0.32` alone is insufficient to convert 700 kg to 2,187/2,188 pcs. Require units (mm/m), width, thickness, density, cavities, layout and net usable mass.
- **Rounding:** floor for countable theoretical whole units unless approved engineering policy specifies another method; store unrounded values and formula version, show estimated ranges when inputs uncertain. Never silently round stock movements.
- **Shared material:** one coil can feed multiple parts/WO; allocate consumed quantity once, prevent summing alternative theoretical outputs as independent inventory.

## Distinct reconciliation metrics
1. **Physical material discrepancy:** counted physical quantity minus expected ledger balance in material UOM (kg/sheets); subject to stock count investigation.
2. **Yield variance:** theoretical output based on **actual consumed** material and process standards minus accounted output-equivalents; compare like-for-like and disclose estimation basis.
3. **Process loss:** measured trim, setup, scrap, remnant and yield losses, with reason codes; not automatically unexplained.
4. **Unexplained output-equivalent variance:** expected equivalent pcs minus good, recorded NG, accountable intermediate output, documented scrap equivalents and other valid outputs. Only calculate once all quantities share a justified conversion basis; show provisional status otherwise.
5. **FG reconciliation:** QC accepted, FG received, reserved, shipped and returned are separate stages; never infer them from good production output alone.

## Worked scenarios
**Sheet A:** 80 sheets × 10 pcs = 800 theoretical; 760 good + 5 NG = 765 accounted pcs; **35 pcs preliminary variance** *only if all 80 sheets were actually consumed, no other outputs/remnants/scrap and standard is valid*. If 3 sheets remain unconsumed, recalculate on 77 sheets: 770 theoretical → 5 pcs provisional variance (assuming no other outputs). Record 3 sheets as Material at Production, not consumed.

**Sheet B:** 79 consumed sheets × 10 = 790 theoretical; 760 good + 5 NG + 0 documented other = **25 pcs unexplained yield variance**, pending investigation. The 25 pcs is **not confirmed physical loss**.

**Coil:** 700 kg issued; example theoretical 2,188 pcs from *validated* material/geometry, 760 good, 5 NG. Do **not** label remaining 1,423 pcs lost: require actual kg consumed, remaining coil/remnant, setup scrap, other runs and output accounting. If no validated conversion, display `Not calculable` with missing inputs.

## Investigation workflow
`Draft/Provisional → Reconciliation Required → Investigation Open → Evidence Collected → Reviewed → Approved Resolution → Closed` (with reopen/reversal policies). Thresholds configurable by tenant/plant/item/process and based on both absolute and relative variance; critical exceptions may block WO final close. Maker-checker for stock adjustments. Store cause code, owner, due date, attachments, measured quantities, before/after, approvals and ledger reference.

## Dashboard KPIs
Issued RM, material at production, actual consumed, remnant, return, documented scrap, theoretical pcs, good, NG, Pending QC, QC accepted, FG received, provisional unexplained variance, confirmed physical discrepancy, case aging and resolution. Drill-down: part → WO/run → lot → issue → consumption → output → QC → case.

## Required tests
Sheet 80/800/760/5=35 provisional; sheet 79/790/760/5=25; issued vs consumed difference; remaining material; multi-WO coil allocation; unit mismatch; pitch without units; over-reporting; zero/negative inputs; stale conversion version; partial QC; duplicate posting; cross-tenant isolation; close blocking and reversal.

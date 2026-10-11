# ADR-0005 — Variance indicators, rounding, tolerance and finality

- **Status:** Accepted (D-012) · **Date:** 2026-10-08 · Formulas are normative in `docs/02-MATERIAL-CONVERSION-RECONCILIATION.md`.

## Context
The audit found the yield basis ambiguous (gross vs expected-after-yield), risking NG double counting. Owner requires three separate indicators with formula, unit, rounding, tolerance and finality.

## Decision
Scope of calculation: a **production run**, aggregated to **WO** and to **input lot** (never summed across alternative theoretical outputs of a shared coil). Symbols:

| Symbol | Meaning | Unit | Source |
|---|---|---|---|
| `C` | consumed input, measured (ADR-0003) | sheet or kg | Production Posting consume leg |
| `CP` | conversion profile version (validated, effective at production date) | — | Planning/Engineering master |
| `T` | theoretical gross pcs from `C` via `CP` | pcs, integer (floor) | calculated |
| `G, NR, NJ` | Good, NG Reworkable, NG Rejected | pcs | Production Posting produce legs |
| `O` | other documented pcs outputs (destructive test samples, retained samples) | pcs | documented legs with reason |
| `SY` | standard yield factor (expected good ÷ gross), versioned | ratio 0–1 | routing/operation standard |
| `NW` | validated net weight per pc | kg/pc | item engineering data, versioned |
| `S` | weighed process scrap | kg | Production Posting scrap legs |
| `Ckg` | `C` in kg (direct, or sheets × validated sheet weight) | kg | calculated |

### I-1 Gross Yield Variance (GYV) — pieces
`GYV = T − (G + NR + NJ + O)` · `GYV% = GYV ÷ T`
Question answered: "How many pieces that the consumed material should geometrically yield are not accounted for by any reported piece?" NG is **counted as accounted output** (it physically exists). Positive = fewer pieces than theoretical.

### I-2 Performance vs Standard (PvS) — pieces
`E = floor(T × SY)` · `PvS = G − E` · `PvS% = PvS ÷ E`
Question answered: "Did we make more or fewer good pieces than the standard expects?" Because `SY` already includes expected NG/process loss, NG is **not** subtracted again. PvS is a performance KPI, **not** a loss or variance case trigger unless a tenant explicitly configures one.

### I-3 Unexplained Material Variance (UMV) — material mass
`UMV = Ckg − ((G + NR + NJ + O) × NW + S + D)` where `D` = other documented material outputs in kg (e.g. lab samples of raw material). `UMV% = UMV ÷ Ckg`
Question answered: "How much consumed material mass is not explained by weighed scrap and the mass of reported pieces?" Independent of nesting/pitch theory; depends on measured weights. Positive = mass unaccounted; negative = over-reporting or wrong `NW`/scale — both investigated.

### Not one of the three
- **Physical Stock Discrepancy** exists **only** from an approved stock count variance (doc 02 metric 1).
- **Actual Material Loss** exists **only** as an approved investigation outcome (`ACTUAL_MATERIAL_LOSS_CONFIRMED`, D-024) backed by evidence and a stock adjustment.
- No indicator above may itself be labelled "loss", "theft" or "missing stock"; an indicator can *lead to* one of the two outcomes above, never replace them.
- **Remaining Material at Production** is stock, never variance.

### Rounding
| Quantity | Storage | Display |
|---|---|---|
| `T`, `E` | unrounded value stored (`NUMERIC(20,6)`) + floored integer | integer pcs |
| `GYV`, `PvS` | integer pcs (from floored `T`/`E`) | integer, signed |
| `UMV`, `Ckg`, `S` | `NUMERIC(20,6)` | at scale resolution (e.g. 0.1 kg), half-even |
| percentages | from unrounded operands | 2 decimals, half-even |
Calculation stores `formula_code`, `CP`/`SY`/`NW` versions and all inputs (snapshot), so results are reproducible.

### Tolerance
Configured per tenant → plant → item → operation (most specific wins), separately for GYV and UMV:
`breach = |v| > abs_tol AND |v%| > pct_tol`. For UMV, effective `abs_tol ≥ scale_resolution × number_of_weighings` (measurement floor). If no tolerance configured (D-061): **strict** — any FINAL `|v|` above the measurement floor (GYV: ≥ 1 pc) opens a case. **OPEN contradiction O-033:** D-061 / BR-REC-03 say "any non-zero" variance, without the measurement-floor exception; the owner decides which threshold applies before B8 (M5). Until then neither reading is normative for implementation. Negative breaches open cases too.

### Status and finality
Each indicator per scope has status `NOT_CALCULABLE → PROVISIONAL → FINAL`; `FINAL` may return to `PROVISIONAL` only through a reversal of an included document (history kept as calculation versions).

| Indicator | NOT_CALCULABLE when | FINAL only when (all) |
|---|---|---|
| GYV | `CP` missing/unvalidated, unit mismatch, `C` absent | run/WO reporting closed; every consume line measured (no `UNMEASURED_BY_DIFFERENCE`); `CP` validated and effective; no draft postings or pending reversals in scope; NG classification confirmed by Quality or QC confirmation window elapsed per tenant policy |
| PvS | GYV not calculable or `SY` missing | GYV FINAL and `SY` version effective |
| UMV | `NW` missing, `Ckg` not derivable, no scrap posting and no "zero scrap" confirmation | consumption measured; all scrap weighed (or zero confirmed by Production Leader); remnant/return documents posted; `NW` validated; no pending reversals |
UI shows missing inputs explicitly, e.g. `Not calculable — missing: thickness, density` (doc 14 #5). Cases may be opened on PROVISIONAL values for visibility but cannot be **resolved** until the indicator is FINAL or an approved exception records why finality is impossible.

### Worked check (from doc 02 / doc 14)
| Case | T | G+NR+NJ+O | GYV | GYV% |
|---|---|---|---|---|
| Sheet A (80 consumed) | 800 | 765 | 35 | 4.38% |
| Sheet A' (77 consumed, 3 remain) | 770 | 765 | 5 | 0.65% |
| Sheet B (79 consumed, 1 returned) | 790 | 765 | 25 | 3.16% |
| Coil sample (doc 02) | 1,688 | 1,641 | 47 | 2.78% |
Coil sample UMV: `612.400 − (1,641 × 0.290 + 128.900) = 7.610 kg` (1.24%). Sample PvS with `SY = 0.97`: `E = 1,637`, `PvS = 1,620 − 1,637 = −17` pcs.

## Consequences
+ Three questions, three numbers, no double counting of NG.
+ Finality makes "provisional" explicit in UI, reports and cases.
− Requires master data (`CP`, `SY`, `NW`) quality; without it indicators stay NOT_CALCULABLE — by design.

## Verification
Golden tests for all rows above; NG never subtracted in PvS; unit-mismatch and pitch-without-unit return NOT_CALCULABLE; by-difference consumption blocks FINAL; reversal reopens to PROVISIONAL with versioned history; tolerance resolution order.

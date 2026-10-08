# ADR-0003 — Actual material consumption measurement

- **Status:** Accepted (D-010) · **Date:** 2026-10-08

## Context
If consumption is derived as `issued − returned`, the physical balance at Material at Production is zero by construction and any physical shortage silently becomes yield variance. Owner decision: consumption must be backed by independent measurement.

## Decision
1. Every consumption line carries `measurement_method`:
   | Method | Applies to | Evidence |
   |---|---|---|
   | `WEIGHED_REMAINING` | coil, bulk kg | scale ID, gross/tare, weighed remaining lot or remnant |
   | `COUNTED_REMAINING` | sheets, blanks, pcs inputs | counted remaining quantity, counter |
   | `WEIGHED_CONSUMED` | direct weighing of consumed input | scale ID |
   | `MEASURED_LENGTH` | coil with length meter | meter reading start/end |
   | `UNMEASURED_BY_DIFFERENCE` | fallback only | reason required |
2. `consumed = opening_at_production(lot, work center) + issued_in − measured_remaining − returned − transferred_out` for the `*_REMAINING` methods; the measured quantity is the independent observation.
3. `UNMEASURED_BY_DIFFERENCE` postings are flagged `PROVISIONAL`, **automatically open a Reconciliation Case** (type `UNMEASURED_CONSUMPTION`), and make every dependent variance indicator `PROVISIONAL` (ADR-0005). The run/WO cannot reach `FINAL` reconciliation until a measurement or an approved exception replaces it.
4. A second, independent output-side signal is stored where available: stroke counter readings per die/machine (`strokes_start`, `strokes_end`). It feeds theoretical output and cross-checks consumption.
5. Scale/precision: measured kg stored as `NUMERIC(20,6)`; scale resolution recorded per scale (e.g. 0.1 kg) and used as minimum tolerance floor in ADR-0005.
6. Who posts: Production Leader posts consumption at run end (or shift end for multi-shift runs); Materials PIC may co-sign measured return. Two-step custody (tenant option) requires the receiving side to accept issued quantity before it is available at `MAT_AT_PROD`.

## Consequences
+ Physical discrepancy at production becomes detectable and separable from yield.
− Requires scales/count discipline on the floor; PWA must make weighing entry fast (Floor Mode scan + numeric pad).

## Verification
Tests: by-difference posting opens case and blocks FINAL; measured remaining produces correct consumed; consumption cannot exceed available at `MAT_AT_PROD` (negative stock rule); multi-shift run consumption split by shift.

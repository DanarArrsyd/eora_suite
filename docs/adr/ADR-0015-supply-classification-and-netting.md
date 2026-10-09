# ADR-0015 — Supply classification and MPS netting method

- **Status:** Accepted — supply classes (D-043) and netting method (D-046, with conditions on safety stock status and BOM fields) · **Date:** 2026-10-09

## Context
Planning, delivery promise and Control Tower risk must not treat projected or unproven quantities as stock. The PROPOSED BR-PLN-05 wording netted per bucket and double counted supply (doc 23 §4).

## Decision
1. (Accepted, D-043) Supply classes A Firm Available, B Projected (dated, sourced, confidence class), C Pending QC (monitoring only, never netted, no expected-acceptance rate in R1).
2. (Proposed) Time-phased projected available balance per plant and item over ISO weekly buckets with opening `OH − AL_out − SS`, class B receipts in their expected bucket, `NR(b) = max(0, −PAB(b))`, lot-for-lot, planned-order due date = earliest uncovered daily due date (doc 23 §5).
3. (Proposed) MRP in material UOM using BOM gross quantity per piece (O-026).

## Consequences
+ No double counting of FG, allocations or QC-released stock; daily deliveries protected.
− Conservative toward Pending QC (possible re-planning); planner sees it explicitly.

## Verification
Doc 23 §7 (#51–#61), doc 14 #63–#64.

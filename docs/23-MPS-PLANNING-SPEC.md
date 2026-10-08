# 23 — Basic MPS Planning Specification (R1)

**Status:** scope DECIDED (D-031, D-035); bucket DECIDED (D-041); netting formula **PROPOSED** (BR-PLN-05) · **Canonical for:** MPS buckets, calendar and demand inputs. Formulas live in doc 15 (do not restate them elsewhere). No backend or schema is authorized yet (D-037).

## 1. Scope (R1, D-031 / D-035)
Demand aggregation, daily/weekly horizon, FG availability, scheduled production supply, net production requirement, basic material and capacity check, planned order → WO linkage, plan revision and approval. Out of R1: APS optimisation, automated scheduling, advanced daily planning (R2).

## 2. Inputs (single sources — no duplicates, D-034)
| Input | Source | Rule |
|---|---|---|
| Firm demand | Delivery Schedule Lines of SO lines | BR-DEM-07 (outstanding only; Unscheduled Blanket Balance excluded, D-039) |
| Forecast | forecast versions | BR-DEM-08 — consumed within the bucket by customer + part + plant; uncertain matches stay unconsumed with an exception (D-040) |
| FG availability | inventory ledger projection | UNRESTRICTED − allocations; Pending QC excluded (D-014) |
| Scheduled supply | released WOs and planned orders | BR-PLN-05 (PROPOSED) |
| Material availability | material balances in material UOM | BR-PLN-06 (PROPOSED); estimated pcs is never stock |

## 3. Buckets and calendar (D-041, BR-DEM-13)
- Requirement dates are stored as actual calendar dates of the plant (timezone Asia/Jakarta).
- **Default netting bucket: weekly.** Week start comes from the plant business calendar (proposed default: ISO week, Monday 00:00 WIB). One date belongs to exactly one bucket.
- Daily granularity is preserved: delivery schedules and daily delivery monitoring show customer due dates per day; weekly totals never replace them, and a daily shortfall inside a covered week is still a risk (doc 14 #47, #48).
- Monthly aggregation is a dashboard view only.

## 4. Output records
MPS versions and lines per BR-PLN-04; requirement records are **derived** and pegged to schedule lines / forecast lines, unique per version/item/bucket/pegged line (BR-DEM-12); never a demand source.

## 5. UI in Phase 3
The Control Tower shows the **weekly demand view** (firm outstanding, forecast, net forecast, outstanding demand for planning, Unscheduled Blanket Balance, exceptions) as read-only mock data (doc 19 CT-9). The net production requirement (BR-PLN-05) is **not** shown as a final KPI while it is PROPOSED.

## 6. Open items
- Confirm BR-PLN-04..07 (PROPOSED) before MPS backend work.
- Confirm the week-start default per tenant onboarding.

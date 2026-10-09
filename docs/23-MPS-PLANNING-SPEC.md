# 23 — Basic MPS Planning Specification (R1)

**Status:** scope DECIDED (D-031, D-035); bucket and calendar DECIDED (D-041, D-044); supply classes DECIDED (D-043); BR-PLN-04..08 **APPROVED / APPROVED WITH CONDITIONS (D-046, 2026-10-09)** — review in §4–§8 kept as rationale · **Canonical for:** MPS buckets, calendar and demand inputs. Formulas live in doc 15 (do not restate them elsewhere). No backend or schema is authorized yet (D-037).

## 1. Scope (R1, D-031 / D-035)
Demand aggregation, daily/weekly horizon, FG availability, scheduled production supply, net production requirement, basic material and capacity check, planned order → WO linkage, plan revision and approval. Out of R1: APS optimisation, automated scheduling, advanced daily planning (R2).

## 2. Inputs (single sources — no duplicates, D-034)
| Input | Source | Rule |
|---|---|---|
| Firm demand | Delivery Schedule Lines of SO lines | BR-DEM-07 (outstanding only; Unscheduled Blanket Balance excluded, D-039) |
| Forecast | forecast versions | BR-DEM-08 — consumed within the bucket by customer + part + plant; uncertain matches stay unconsumed with an exception (D-040) |
| FG availability | inventory ledger projection | **Firm Available Supply** (D-043 class A): FG_WAREHOUSE / UNRESTRICTED, not blocked |
| Projected supply | QC released not received, released WOs, confirmed supply | D-043 class B, each dated with confidence class |
| Pending QC | inventory ledger projection | D-043 class C: monitoring only, **never netted** |
| Material availability | material balances in material UOM | BR-PLN-06 (PROPOSED); estimated pcs is never stock |

## 3. Buckets and calendar (D-041, BR-DEM-13)
- Requirement dates are stored as actual calendar dates of the plant (timezone Asia/Jakarta).
- **Default netting bucket: weekly, ISO 8601 Monday 00:00 → Sunday 24:00, Asia/Jakarta** (D-044); tenant default calendar with plant override. One production/requirement date belongs to exactly one bucket. Time concepts (production date, shift date, cut-off) are defined in doc 04 §Time model.
- Daily granularity is preserved: delivery schedules and daily delivery monitoring show customer due dates per day; weekly totals never replace them, and a daily shortfall inside a covered week is still a risk (doc 14 #47, #48).
- Monthly aggregation is a dashboard view only.

## 4. Output records
MPS versions and lines per BR-PLN-04; requirement records are **derived** and pegged to schedule lines / forecast lines, unique per version/item/bucket/pegged line (BR-DEM-12); never a demand source.

## 5. UI in Phase 3
The Control Tower shows the **weekly demand view** (firm outstanding, forecast, net forecast, outstanding demand for planning, Unscheduled Blanket Balance, exceptions) as read-only mock data (doc 19 CT-9). The net production requirement (BR-PLN-05) is **not** shown as a final KPI while it is PROPOSED.

## 4. Phase 3.5 review of BR-PLN-04..07 (rationale; outcome approved by D-046)
Notation (per plant `p`, FG item `i`, ISO week bucket `b`, all quantities in **pcs of item i** unless stated):
`D(b)` outstanding demand for planning (BR-DEM-07/08) · `OH` FG on-hand UNRESTRICTED in FG_WAREHOUSE (class A before allocations) · `AL_out` allocations of that FG to demand **outside** the planning horizon/scope · `QR(b)` QC released not yet received with expected FG-receipt date in b (class B) · `WR(b)` remaining output of released WOs due in b = max(0, WO qty − good posted) (class B) · `PO(b)` firm planned orders already approved in an earlier MPS version due in b (class B) · `PQ` Pending QC (class C, not netted) · `SS` safety stock (O-023, default 0).

### Finding F-1 — per-bucket formula double counts FG (critical)
**Evidence:** BR-PLN-05 text: "Net requirement per item/bucket = demand − FG unrestricted available − QC released − scheduled supply …" applied to **each** bucket. **Impact:** the same FG and the same QC-released quantity reduce every week's requirement → requirement understated, deliveries at risk. **Recommendation:** time-phased projected available balance (standard MPS logic), opening supply applied once:
```
PAB(b0−1) = OH − AL_out − SS
PAB(b)    = PAB(b−1) + QR(b) + WR(b) + PO(b) − D(b)
NR(b)     = max(0, −PAB(b))           -- net production requirement in bucket b
PAB(b)    = max(PAB(b), 0) after NR(b) is planned (lot-for-lot)
```
**Decision required:** O-022 / gate G-MPS-1.

### Finding F-2 — allocated FG counted twice (high)
**Evidence:** "FG unrestricted **available**" = on-hand − allocations; but allocations reference schedule lines whose outstanding is still in `D(b)`. **Impact:** allocated stock neither covers its own demand nor counts as supply → requirement overstated by the allocated quantity. **Recommendation:** use on-hand `OH` and subtract only allocations to demand outside the netting scope (`AL_out`); allocations inside the horizon are already covered by their demand lines. **Decision:** included in G-MPS-1.

### Finding F-3 — Pending QC clause conflicts with D-043 (resolved by owner)
BR-PLN-05 "Pending QC excluded unless tenant policy says otherwise" → tenant override removed for R1 (D-043). `PQ` is shown beside `NR` as "Pending QC (not netted)". Consequence (re-planning quantity that later passes QC) is a known, accepted planning conservatism → gate G-MPS-3 (O-024) only asks the owner to confirm the display rule.

### Finding F-4 — safety stock undefined (medium) → O-023, R1 default `SS = 0`.

### Finding F-5 — daily due-date protection (high)
Weekly netting can cover a week while a Tuesday due precedes a Thursday receipt. **Recommendation:** (a) MPS planned order **due date = earliest uncovered daily due date** inside the bucket (time-phased daily check within the bucket using the same PAB rule at day granularity); (b) CT-8 keeps the per-due-date risk view (doc 14 #48). Gate G-MPS-4 (O-025).

### Finding F-6 — WO remaining output vs Pending QC (medium)
`WR = WO qty − good posted`: good output already posted sits in Pending QC (not netted) or later stages (QC released → class B, FG → class A). Counting `WR` on `WO qty` instead would double count posted output. **Rule:** good posted leaves `WR` exactly once and appears in its stage class.

### Finding F-7 — MRP UOM conversion undefined (high) → O-026 (gate G-MPS-5): MRP needs pcs → material quantity; doc 02 only defines material → pcs. Proposed BOM gross quantity per piece in material UOM, versioned and validated against the conversion profile.

### Finding F-8 — WOs outside MPS (medium) → O-027 (G-MPS-6). Finding F-9 — capacity data missing (medium) → O-028 (G-MPS-7). Finding F-10 — multi-plant scope (low for R1) → O-029 (G-MPS-8).

## 5. Rule-by-rule specification (proposed wording for approval)
| Rule | Formula / behaviour | Inputs | Output | UOM | Exceptions |
|---|---|---|---|---|---|
| **BR-PLN-04** MPS versioning | MPS version per plant × horizon (default 12 ISO weeks); states DRAFT → SUBMITTED → APPROVED → SUPERSEDED; approving a new version supersedes the previous; only APPROVED lines feed planned-order release (manual WO path per O-027) | demand snapshot id, supply snapshot `as_of`, calendar version | `mps_versions`, `mps_lines`, `mps_requirement_pegs` | pcs | approval by PPIC lead ≠ preparer (SoD proposal); stale snapshot (> tenant threshold) blocks approval |
| **BR-PLN-05** Net production requirement | time-phased PAB (§4 F-1/F-2), class A opening once, class B dated, class C excluded, `SS` default 0, lot-for-lot | `D(b)` (BR-DEM-07/08/13), `OH`, `AL_out`, `QR`, `WR`, `PO`, `SS` | `NR(b)`, `PAB(b)`, pegs to demand lines | pcs of FG item | uncertain forecast and Unscheduled Blanket Balance never in `D`; negative PAB carried as requirement, never as negative stock |
| **BR-PLN-06** Basic MRP | `gross_req_m(b_rel) = Σ planned_qty × bom_gross_qty_per (O-026)` offset by routing lead time (days, default 0); `net_m = max(0, gross − (on-hand UNRESTRICTED material − material allocations to other WOs) − open material receipts)`; Material at Production allocated to the WO counts as supply for that WO only | planned orders, BOM version, material balances, allocations | `material_requirements` with shortage flag | material UOM (kg / sheet); never estimated pcs | material in HOLD/BLOCKED/PENDING not supply; substitutions only via approved substitution record |
| **BR-PLN-07** Planned order → WO; capacity | explicit release action creates WO with BR-PLN-01 snapshot and links pegs; capacity load = planned qty × std cycle time / (shift hours × efficiency) per work center per day; warning when > 100 % | planned orders, routing ops, capacity calendar (O-028) | WO, warnings | pcs; hours | warns, never optimises or reschedules automatically |

## 6. Worked example (sample data of doc 14 / Control Tower mocks, PART-A, plant PA)
Inputs: `D` W41 200 (150 firm + 50 net forecast), W42 1,200, W43 300, W44 250 · `OH` 240 (of which 120 allocated to DSL-2, a W42 line inside the horizon → `AL_out` = 0) · `WR`: WO-2214 qty 2,000 − good 1,620 = 380 due W42 · `QR` 0 · `PQ` 80 (shown, not netted) · `SS` 0.

| Bucket | PAB start | + receipts | − demand | PAB end | NR |
|---|---|---|---|---|---|
| W41 | 240 | 0 | 200 | 40 | 0 |
| W42 | 40 | 380 | 1,200 | −780 | **780** (PAB → 0) |
| W43 | 0 | 0 | 300 | −300 | **300** |
| W44 | 0 | 0 | 250 | −250 | **250** |
Total NR 1,330 pcs. Wrong alternatives the rules prevent: per-bucket subtraction of FG (240 subtracted again in **every** week: W41 0, W42 1,200 − 240 − 380 = 580, W43 60, W44 10 → NR 650, understated by 680); available-only opening (120 instead of 240 while DSL-2 stays in demand → NR 80 + 820 + 300 + 250 = 1,450, overstated by 120); counting Pending QC 80 as supply (NR 0 + 700 + 300 + 250 = 1,250 — 80 pcs of unproven quantity treated as supply).
Daily protection (F-5): W42 demand includes DSL-2 due Tue 13 Oct; the 780 planned order due date = 13 Oct, not 18 Oct.

## 7. Acceptance scenarios (proposed; doc 14 numbering continues at #51)
- **#51 Time-phased opening once:** given OH 240 and demands W41 200 / W42 300, then NR W41 0, W42 260 (not 300 − 240 = 60).
- **#52 In-horizon allocation not double counted:** given OH 240 with 120 allocated to a W42 line inside the horizon, NR equals the case without allocation.
- **#53 Out-of-scope allocation reduces supply:** given 100 allocated to a line beyond the horizon, opening PAB = 140.
- **#54 Pending QC not netted:** given PQ 80, NR is unchanged and the MPS line shows "Pending QC (not netted) 80".
- **#55 QC released is dated projected supply:** QR 300 with expected receipt W43 reduces NR from W43 on, never W41/W42.
- **#56 WO remaining:** WO 2,000 with good 1,620 contributes 380, and the 1,620 appear only in their stage class.
- **#57 Uncertain forecast and unscheduled blanket excluded from D** (D-039, D-040).
- **#58 Daily due-date protection:** planned order due date = earliest uncovered daily due date in the bucket.
- **#59 Plant scope:** FG in plant B is not supply for plant A.
- **#60 MRP in material UOM:** planned 1,000 pcs × BOM gross 0.318 kg/pc = 318.000 kg requirement compared with RM UNRESTRICTED − other allocations; estimated pcs never used.
- **#65 F-1 regression (required unit test, D-046):** PART-A sample of §6 must give NR 780 / 300 / 250 = 1,330 pcs; the per-bucket formula result 650 (680 short) must be impossible.
- **#66 Receipt after due date:** a WO receipt dated Thu does not cover a Tue due in the same bucket; Tue shows a daily shortage and the planned order due date is Tue minus production lead time.
- **#67 Safety stock status:** item without policy shows safety stock 0 with status `NOT CONFIGURED`; configuring 50 for item + plant raises the opening deduction by 50.
- **#68 Scrap allowance once:** BOM quantity per pc already including a 3 % scrap allowance is not multiplied by the yield factor again.
- **#61 Approved version only:** release from a DRAFT MPS line is rejected; manual WO path requires reason + approval and is flagged.

## 8. Approval gates for the owner
| Gate | Question | Proposed default |
|---|---|---|
| G-MPS-1 (O-022) | Time-phased PAB with OH − AL_out opening | **APPROVED** (D-046); F-1 680 pcs unit test required |
| G-MPS-2 (O-023) | Safety stock in R1 | **APPROVED WITH CONDITIONS**: default 0 shown as `NOT CONFIGURED`; per item + plant |
| G-MPS-3 (O-024) | Pending QC display beside NR, never netted | **APPROVED** |
| G-MPS-4 (O-025) | Lot-for-lot; earliest uncovered daily due | **APPROVED** + receipt after due never satisfies it; lead time considered |
| G-MPS-5 (O-026) | BOM gross qty per piece in material UOM | **APPROVED WITH CONDITIONS**: BOM line fields, scrap/yield applied once, engineering approval |
| G-MPS-6 (O-027) | Manual WO outside MPS | **APPROVED**: reason code, responsible user, approval, audit, planning-exception ref |
| G-MPS-7 (O-028) | Capacity warning | **APPROVED**: machine + dies availability, shift calendar, std cycle time |
| G-MPS-8 (O-029) | Netting per plant | **APPROVED**; inter-plant only via authorized MAT_TRANSFER |

## 9. Open items
- Confirm BR-PLN-04..07 (PROPOSED) through gates G-MPS-1..8 before MPS backend work.
- ~~Confirm the week-start default~~ → decided ISO Monday, tenant → plant override (D-044).

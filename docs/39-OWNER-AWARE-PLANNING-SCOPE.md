# 39 — Owner-aware planning (scope proposal)

Status: **APPROVED, 2026-11-22 (D-141)** — owner: "Approve, pakai usulan"; §4 D-1..D-6 decided as proposed.
Builds on D-135 / doc 37 / ADR-0020 (material ownership), D-134 / doc 36 (Daily Recovery), D-139 / doc 38 §12 (Generate SPK) and M5 doc 34 (MPS, MRP, planned order → WO).

## 1. Why

Customers (DPM, SJM, AMI, SUP) can supply their own material. Lot ownership is already immutable in the ledger: customer-owned lots can only be issued to, produced for, allocated to and delivered for that customer (D-135). Planning does not know this yet. MPS, MRP, Daily Recovery and Generate SPK add up stock per item for the whole plant, so a customer's coil would cover another customer's shortage on paper while the issue screen refuses it on the floor.

To prevent that wrong answer, every new MPS, MRP, Daily Recovery and Generate SPK calculation in a plant that holds any customer-owned stock is refused today (`409 OWNER_PEGGING_REQUIRED`). The result: the first customer lot received stops automatic planning for **every** customer at that plant. That is why migration `2026_11_18_005000` is not applied to the operational database yet (doc 37 release condition).

This scope makes the four planners owner-aware, so the gate can be removed path by path.

## 2. Today (what the code does)

| Planner | Supply it counts | Demand it nets | Customer dimension |
|---|---|---|---|
| MPS (`MpsInputs`, `MpsEngine`) | FG on hand (FG warehouse + staging), Pending QC (projected, never netted, D-043), QC released, remaining WO output, minus allocations outside the horizon, minus safety stock | Firm schedule lines + forecast, per item per day | None: one row per item |
| MRP (`MrpPlanner`) | RM / Semi-Finished on hand (not Material at Production, staging, transit), committed to released WOs, inbound schedule | Planned FG orders from the approved MPS, exploded per BOM | None; released WOs have no production customer |
| Daily Recovery (`DailyRecoveryPlanner`, `MaterialCapacity`) | FG pool per item (unallocated FG, Before Check, QC accepted, unattributed WO remainder); RM pool per item for material capacity | Schedule lines per customer + part up to the target date | Demand is per customer + part; supply pools are per item only. WOs with a customer stay with that customer |
| Generate SPK (`SpkGenerator`) | Daily Recovery result + safety stock | Daily Recovery shortfall | Inherits Daily Recovery; generated WOs carry no production customer |

## 3. Proposed behaviour

### 3.1 One rule for all four planners
- Every supply quantity carries its **owner**: `COMPANY` or `CUSTOMER:<id>`. Owner comes from the lot (ledger), from the WO's production customer (WO remainder), or from the inbound schedule (§4 D-4).
- **Customer-owned supply covers only that customer's demand.** It is never counted for another customer, never shown as shared availability, and never netted twice.
- **Company supply is shared, counted once.** It covers any customer's demand after that customer's own supply, in one order (§4 D-2).
- Pending QC stays projected and never netted (D-043). Before Check → QC accepted → FG received never increases total coverage; QC NG reduces it (D-134). These rules apply inside each owner pool.
- Old MPS / MRP snapshots and existing WOs stay as they were. New columns are nullable and filled only for new calculations.

### 3.2 MPS
- Rows stay **per item** (the screen does not explode into customers), with a breakdown per owner for each item that has customer-owned supply: own supply, company supply used, shortfall.
- Netting per day: demand of customer C first consumes C's own supply, then the shared company pool. A customer's surplus stays with that customer and is shown as such (e.g. the paired blanking 360 vs 350).
- Planned orders: one per item and week as today, **split per production customer** only where the order must use customer-owned material (§3.3). Unattributed demand stays one company order.

### 3.3 MRP
- Material need is netted per owner: a customer part whose BOM component that customer supplies nets against that customer's lots first, then against company stock if the customer allows it (§4 D-3).
- The shortage list says **who must act**: "customer to supply" (ask the customer for material) vs "company to buy". No purchase document (M5-D5 unchanged).
- Release to WO sets `production_customer_id` on every WO that consumes, or may consume, customer-owned material, so issue validation (D-135) passes. Semi-Finished child WOs inherit the parent's customer.

### 3.4 Daily Recovery
- FG pool, Before Check, QC accepted and the RM material-capacity pool become **per item and owner**. Each customer + part row uses its own pool, then the shared company pool.
- The detail panel shows the owner of every lot used (already listed per lot) and the split "own / company" per supply line.
- Create draft WO sets the production customer when the plan needs that customer's material.

### 3.5 Generate SPK
- Takes the owner-aware Daily Recovery result unchanged. Each generated WO carries the production customer of the plan line it covers. An SPK row never mixes owners (one posting = one owner, D-135).

### 3.6 Removing the gate
`requireAggregatePlanning` is removed per path only when that path is owner-aware and its tests below pass: Daily Recovery + Generate SPK, then MPS, then MRP + release. Until a path is done it keeps the 409.

## 4. Decisions needed from the owner

Decided 2026-11-22 (D-141): every proposal below as marked.

| ID | Question | Options | Proposed |
|---|---|---|---|
| D-1 | When a customer part has both customer-supplied and company material available, which is used first? | (a) customer's own first, company only for the rest; (b) company first; (c) planner chooses per order | **(a)** — consignment is used before company money |
| D-2 | Two customers compete for the same company stock (FG or RM): who gets it? | (a) earliest due date first, then customer code; (b) fixed customer priority list per plant; (c) share proportionally | **(a)** — same rule as Daily Recovery today |
| D-3 | May company material cover a customer's part when that customer normally supplies the material (customer stock short)? | (a) yes, always (D-135 allows it for compatible specs); (b) only when the customer is marked "company may top up"; (c) never — show "customer to supply" | **(b)** keeps it explicit per customer |
| D-4 | Inbound schedule for customer-supplied material: does it need an owner field (expected coil from DPM)? | (a) yes, owner on inbound line; (b) no, customer material counts only when received | **(a)** — otherwise MRP shows "customer to supply" for coil already announced |
| D-5 | Safety stock: does customer-owned FG count toward the item's safety stock? | (a) only company stock; (b) all stock of the item | **(a)** — a customer's stock cannot protect other customers |
| D-6 | MPS view: per item with an owner breakdown, or separate rows per customer + item? | (a) per item + breakdown; (b) rows per customer | **(a)** — keeps the screen short; breakdown only where customer stock exists |
| D-7 | Delivery order: this scope stays planning only. Allocation/delivery already enforce ownership (D-135). | — | No change |

## 5. Out of scope
Mixed-owner postings, ownership transfer, cross-customer exceptions (deferred by D-135); purchasing documents; Material Monitoring report and NG provenance (next scope, doc 35); applying the ownership migration to the operational database (owner's release step after UAT).

## 6. Delivery (slices, each its own PR)
1. **Owner pools** — one shared read of supply per item + owner (FG, Pending QC, QC released, RM, WO remainder, inbound) used by all four planners; unit tests on the pool.
2. **Daily Recovery + Generate SPK** owner-aware, WO production customer set; gate removed for these paths.
3. **MPS** owner breakdown + netting; snapshot columns; gate removed for MPS.
4. **MRP + release to WO** per-owner netting, "customer to supply / company to buy", production customer on released WOs; gate removed.
5. **Docs + UAT**: decision row, BR rows (doc 15), doc 36/38 notes, role guides (Indonesian), UAT script; owner runs factory UAT; then owner decides when to apply migration `005000` to the operational database.

Each slice: migration + rollback where needed, RLS tests as the runtime role, OpenAPI without drift, mocked e2e + stack e2e, CI green + Codex clean before merge.

**Progress:** Slices 1–2 (PR `feat/owner-planning-1`, 2026-11-22): owner pools in `MaterialCapacity` (`owner|item`) and `RecoveryAllocation` (FG per owner); Daily Recovery and Generate SPK owner-aware and no longer gated; customer master field *Company material may top up* (migration `2026_11_22_005400`); material capacity counts whole one-owner runs (D-135: one posting = one owner) — the customer's own run first, then a company run only where company material may serve every component; customer-bound surplus never stands in for company safety stock; the owner rules are part of the plan fingerprint; a material counts as customer-supplied once the customer owns a lot of it (D-4 inbound owner follows with MRP, slice 4). MPS and MRP keep the 409 until slices 3–4.

## 7. Acceptance
1. Two customers, one company RM pool: each gets company stock by D-2 order; neither sees the other's customer stock; totals counted once.
2. Customer-owned FG never covers another customer's schedule line in MPS, Daily Recovery or Generate SPK.
3. Customer coil short and D-3 = "no top-up": MRP says "customer to supply", no company buy line for it.
4. Paired blanking: 20 sheets of DPM coil → 360 RH + 360 LH owned by DPM; the 10 LH surplus stays DPM's and covers only DPM demand.
5. Partial carry-over: material left at production from yesterday keeps its owner and is not double counted.
6. QC transitions per owner: Before Check → QC accepted → FG received keeps total coverage; NG reduces only that owner's coverage.
7. Concurrent Generate SPK / create WO on the same plan line: one WO, correct production customer, retry idempotent.
8. Old MPS / MRP snapshots unchanged after migration; the 409 is gone only for paths proven above.

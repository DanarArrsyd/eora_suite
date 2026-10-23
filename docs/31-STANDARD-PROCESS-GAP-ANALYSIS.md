# 31 — Standard ERP Process Benchmark (SAP-style) — Gap Analysis

**Status:** PROPOSED for owner review (2026-10-23). Nothing here changes a decision; every proposal below needs an owner
decision (doc 13) before it affects scope, schema or UI. Requested by the owner: "flow bisnis platformnya kayak SAP".

## 0. Purpose and boundaries
- **What this is:** EORA's end-to-end flow compared with the **standard manufacturing process scenarios** that mature
  ERP suites (SAP S/4HANA being the reference the owner named) are organised around: Plan-to-Produce, Procure-to-Pay,
  Order-to-Cash, Quality, Inventory/Warehouse, Maintenance, Master data. It uses only public, generic process knowledge.
- **What this is not:** no reverse engineering of SAP software (licence terms forbid it, and the REA skill targets
  shipped artifacts, not process design), no copied SAP screens, transaction codes or texts (CLAUDE.md), no change to
  EORA's positioning — doc 00: *not a full accounting/HR ERP or a clone of SAP/Oracle*.
- **How to read it:** "standard" = what a typical ERP manufacturing flow does; "EORA" = approved scope (docs 00, 01, 03,
  11, 21) and what is already built (M1, M2 steps 1–6c). Gaps are classified **Built**, **Planned R1**, **Planned R2/V2**,
  **Deliberately different**, **Excluded**, **Gap → proposal**.

## 1. Scenario map

### 1.1 Plan-to-Produce (make-to-order / make-to-stock, discrete)
| Standard step | EORA | Classification |
|---|---|---|
| Demand management: sales orders + forecasts, consumption of forecast by orders | SO line = single demand identity, schedule lines, forecast consumption same bucket (D-034, D-040, D-041) | Planned R1/R2 (M4/M5) |
| Master production schedule (MPS) on key items | Basic MPS, weekly bucket with daily dates, approval gates (D-031, D-046, doc 23) | Planned R1 (M5) |
| MRP run: explode BOM, net requirements, planned orders, purchase requisitions | Basic MRP, BOM-based, lot-for-lot with lead time, planned production orders (D-046); **no purchase requisitions** (procurement excluded) | Planned R1 (M5); purchasing side → §1.2 |
| Production version = which BOM + routing to use, by validity date / lot size | BOM–routing map per (BOM version, routing version), approved by a second person (D-079); PRIMARY/ALTERNATE routing | Built (M2); lot-size ranges → **Gap → P-07** |
| Convert planned order → production order; snapshot BOM/routing | WO release snapshots BOM + routing + map (B5, BR-PLN-01) | Planned R1 (M4/M5) |
| Material availability check at order release (ATP on components) | Basic material check in MPS; WO release check not specified as a blocking rule | **Gap → P-03** |
| Capacity planning / levelling | Capacity warnings only; APS optimisation excluded (D-046) | Deliberately limited |
| Material staging to production supply area | Issue/transfer to **Material at Production** (`MAP-<wc>`), two-step custody option | Planned R1 (M4) |
| Order confirmation per operation (yield, scrap, activities) | Production posting per run / operation: Good, NG Reworkable, NG Rejected, Scrap, other outputs (ADR-0004) | Planned R1 (M4) |
| Backflush of components at confirmation | **Measured consumption required**; by-difference = provisional + case (D-010, ADR-0003) | **Deliberately different** (keep; P-08) |
| Goods receipt of finished product from the order | Output → Pending QC → QC release → FG receipt (D-011 output categories) | Planned R1 (M4) |
| Order technical completion / close; settlement of variances to finance | WO close rules (O-015 decided, D-063); variance indicators GYV/PvS/UMV are quantities, no cost settlement | Planned R1; costing **Excluded** |
| "Work in process" valuation | No WIP module/label; Material at Production + Semi-Finished Material (D-002) | **Deliberately different** |

### 1.2 Procure-to-Pay
| Standard step | EORA | Classification |
|---|---|---|
| Purchase requisition → purchase order to supplier | Not in V1 ("full supplier procurement" excluded, doc 00) | **Excluded** — but see P-04 |
| Inbound delivery / supplier schedule | Not modelled; MPS "scheduled supply" for RM has no source document | **Gap → P-04** |
| Goods receipt with quality stock / blocked stock | RM receipt by coil/sheet/lot, BLOCKED + manual incoming release (D-062) | Planned R1 (M4) |
| Incoming inspection lot | Deferred (D-062 keeps manual release) | Planned V2 |
| Invoice verification, payment | Excluded (no finance) | **Excluded** |

### 1.3 Order-to-Cash (the delivery part)
| Standard step | EORA | Classification |
|---|---|---|
| Customer master, customer-material info (customer part number) | Customers, customer part mapping with effective ranges | Built (M2) |
| Sales order with schedule lines, revisions | Customer PO → SO header/lines, schedule lines, revisions, blanket remainder (D-034..D-039) | Planned R1 (M4) |
| Availability check / available-to-promise on the order | Supply classes Firm Available / Projected / Pending QC, never promised from Pending QC (D-043) | Data exists; **promise step → Gap → P-03** |
| Credit check, pricing, quotations | Excluded ("full sales ERP", doc 03) | **Excluded** |
| Outbound delivery → picking → packing → goods issue | Delivery order, allocation, pick/pack (R2), shipment, partial shipment | Planned R1/R2 |
| Proof of delivery | POD R2 | Planned R2 |
| Billing / invoice, revenue | Excluded | **Excluded** — export interface only (P-06) |
| Customer returns | V2 "customer returns detail" | Planned V2 |

### 1.4 Quality
| Standard step | EORA | Classification |
|---|---|---|
| Inspection plan / characteristics per operation | Routing operation inspections (characteristic, sampling note) | Built (M2) |
| Inspection lot from production; results recording | Inspection lot on output, partial disposition | Planned R1 |
| Usage decision (accept / reject / rework / scrap) | Disposition Accepted / Hold / Rework / Rejected / Scrap; SoD-01 | Planned R1 |
| Quality stock vs unrestricted vs blocked | Stock statuses UNRESTRICTED / PENDING_QC / HOLD / REWORK / REJECTED / BLOCKED (DD-01) | Planned R1 (M3) |
| Defect codes, quality notifications, CAPA | Defect codes R2; NCR / CAPA V2 | Planned R2/V2 |

### 1.5 Inventory and warehouse
| Standard step | EORA | Classification |
|---|---|---|
| Material document per goods movement, typed by a **movement type** code | Inventory documents with legs (posting matrix rows 1–13, ADR-0014); document *types* exist (`MAT_ISSUE`, `MAT_TRANSFER`, …, D-048) | Planned R1 (M3); explicit movement-type catalogue → **Gap → P-02** |
| Reversal by a counter-document (never delete) | Reversal/adjustment documents, maker-checker for sensitive corrections (doc 15 BR-INV, doc 16 §9) | Planned R1 (M3) |
| Batch management | Lots, lot split/remnant, genealogy | Planned R1 |
| Reservations | Allocations/reservations in the ledger | Planned R1 |
| Physical inventory (count, difference posting) | Stock count / blind count, adjustment with approval | Planned R2 |
| Posting periods / period close | Inventory periods, closed-period correction | Planned R1/R2 |
| Bins, handling units | FG bins R1, handling units R2 | Planned R1/R2 |
| Number ranges per document type | Per tenant/plant/type/year, no gapless promise (D-049) | Planned R1 (M3) |

### 1.6 Maintenance
| Standard step | EORA | Classification |
|---|---|---|
| Equipment / functional location | Machines, dies (stroke life), work centers | Built (M2) |
| Maintenance notification, order, preventive plan | Basic PM, breakdown, die stroke checks | Planned R2 (Maintenance alias, D-076) |

### 1.7 Master data and engineering
| Standard concept | EORA | Classification |
|---|---|---|
| Material master with views per function (planning, quality, warehouse) | One item + material spec + conversions; per-function attributes spread over modules | Built; function views → UI question only |
| Unit-of-measure conversions per material | Versioned, never back-dated (D-073) | Built |
| BOM with validity and alternatives | BOM versions with inclusive validity, approval, cycle check (D-078) | Built |
| Routing with operations, work centers, standard values | Routing versions, operations, standard yield, approval (doc 26) | Built |
| Engineering change number grouping several object changes with one validity | Each object versioned separately with a change reason; no shared change number | **Gap → P-05** |
| Mass upload of master data | Excel import with preview, error file, fenced commit (step 6, D-081..D-083) | Built (screens in 6d) |

### 1.8 Cross-cutting
| Standard concept | EORA | Classification |
|---|---|---|
| **Document flow** (every document shows its predecessors and successors) | Source references on postings; traceability backward/forward (E-14) | Data planned; generic view → **Gap → P-01** |
| Authorisation objects with organisational levels | Permission catalogue + TENANT / COMPANY / PLANT scopes, SoD rules | Built (M1) |
| Workflow / approvals | Maker-checker in lifecycle functions; approval engine (E-03) | Built for master data; R1 for transactions |
| Change documents (who changed what) | Audit events with before/after hashes on every write | Built |
| Finance integration (GL postings from goods movements) | Excluded; quantities only | **Excluded** (P-06) |

## 2. What EORA should keep different on purpose
1. **Measured consumption over backflush** (D-010): backflush hides the variance EORA exists to explain (doc 02). Keep;
   a tenant-level "backflush allowed for item X" option would only ever be PROVISIONAL consumption.
2. **No WIP label** (D-002): Material at Production + Semi-Finished Material stay; WIP valuation belongs to costing,
   which is excluded.
3. **Unexplained variance is never loss** (D-024, D-075): ERP "scrap/variance" postings are not copied as automatic loss.
4. **No finance**: goods movements carry quantities, not values; an export interface replaces GL integration.
5. **Original UI** (ADR-0010): process parity does not mean screen or terminology parity.

## 3. Proposals (all PROPOSED — owner decides)
| ID | Proposal | Why (ERP practice) | Recommendation | Lands in |
|---|---|---|---|---|
| P-01 | **Document flow panel** on every transactional document: predecessors/successors (SO line → WO → issues → postings → inspection → FG receipt → delivery), built from source references + genealogy | Users navigate a flow by its documents; auditors trace one order end to end | Adopt (read model + shared UI component) | M4 (thin flow) |
| P-02 | **Movement-type catalogue**: each inventory document leg carries a stable movement code (e.g. receipt, issue to production, return, transfer, scrap, QC release, delivery, reversal of each), documented in doc 04 with its stock-status effect | Makes the posting matrix explicit, reportable and filterable; reversal = paired code | Adopt — before M3 starts (schema) | M3 |
| P-03 | **Availability check**: (a) WO release checks component availability (Firm Available) and warns/blocks per tenant policy; (b) SO schedule-line "promise date" from Firm + Projected supply, never Pending QC | Standard ATP at order entry and at production-order release | (a) warn-only in R1, (b) R2 | M4 (a), M5/R2 (b) |
| P-04 | **Lightweight supplier PO reference / inbound schedule** (supplier, item, qty, expected date, status) feeding MPS "scheduled supply" and RM receipt — no pricing, approval or invoicing | MRP needs firm inbound supply; today scheduled RM supply has no source | Adopt as reference data only (changes doc 00 exclusion wording) | M5 |
| P-05 | **Change number** grouping BOM/routing/map/conversion versions that must take effect together (one validity date, one approval view) | Engineering change management keeps related versions consistent | Defer to V2; note in doc 26 | V2 |
| P-06 | **Finance export interface**: periodic export of goods movements (quantities, document ids, movement codes) for an external accounting system | Replaces GL integration while finance stays out of scope | Adopt as an Export Center report | M7+ |
| P-07 | **Production-version selection** by lot-size range and validity on the BOM–routing map | Picks the right BOM/routing pair automatically for planned orders | Defer until MRP (M5) shows the need | M5 review |
| P-08 | Confirm **no default backflush** (§2.1) and record it as a rule in doc 15 | Avoids pressure to copy ERP defaults | Confirm | doc only |

## 4. Effect on the current plan
- **M2 (in progress, step 6d next):** no change. Import, BOM/routing/map and conversion data already match standard
  master-data practice.
- **Before M3:** decide **P-02** (movement codes are part of the ledger schema; changing them after M3 means a new
  migration and a data backfill).
- **M4/M5:** P-01, P-03, P-04, P-07 are the candidates; each becomes a decision row (doc 13) plus BR rows (doc 15).
- **Out of scope stays out:** billing, pricing, credit, GL, costing, HR, procurement approvals.

## 5. Next step
Owner picks the proposals to adopt; accepted ones are recorded in doc 13 (D-0xx) and folded into the milestone scope
sheets. Until then, M2 continues with step 6d (import wizard screens).

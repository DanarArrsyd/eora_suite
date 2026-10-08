# 15 — Business Rule Catalog

Each rule: ID, statement, owner module, enforcement point, source decision, acceptance test. **Enforcement** codes: `DB` constraint/RLS · `SVC` domain service validation under transaction · `POL` authorization policy · `UI` guidance only (never sole enforcement). Status: all rules below are DECIDED unless marked *(OPEN)*.

## Tenancy & security (TEN)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-TEN-01 | Every tenant-owned row carries `tenant_id`; FKs between tenant rows are composite with `tenant_id`. | Core | DB | D-009 | T-ISO-02 |
| BR-TEN-02 | Tenant context comes from session membership, never from client input. | Core | SVC/POL | DD-07 | T-ISO-01 |
| BR-TEN-03 | DB tenant context set transaction-locally; unset context returns no rows. | Core | DB | D-009 | T-ISO-03 |
| BR-TEN-04 | Jobs, files, cache keys, channels, exports, notifications carry and check `tenant_id`. | Core | SVC | D-009 | T-ISO-04/05, #12 |
| BR-TEN-05 | Platform admin has no default access to tenant operational data; support access requires tenant-approved, time-boxed, audited grant. *(policy detail OPEN O-007)* | Core | POL | doc 05 | — |

## Ledger & stock (INV)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-INV-01 | Only Inventory posting service writes `inventory_movements` and `stock_balances`. | Inventory | SVC + DB grants | D-003 | arch test |
| BR-INV-02 | Posted movements are append-only; corrections by reversal document. | Inventory | DB grants + SVC | D-007 | #11 |
| BR-INV-03 | Non-transformation documents conserve quantity per item/lot/UOM (Σ legs = 0 incl. virtual locations). | Inventory | SVC + property test | DD-02 | conservation test |
| BR-INV-04 | No physical balance may become negative, any status/location. | Inventory | SVC under row lock + DB `CHECK (qty >= 0)` on balances | D-015 | #10 |
| BR-INV-05 | Balance rows locked in deterministic key order; postings retry on deadlock/serialization (bounded). | Inventory | SVC | ADR-0002 | concurrency test |
| BR-INV-06 | Every posting command carries an idempotency key unique per tenant; replay returns original result. | Inventory | DB unique + SVC | doc 04 | #15 |
| BR-INV-07 | Stock status catalogue fixed (DD-01); status change is a movement. | Inventory | DB enum/check | D-014 | — |
| BR-INV-08 | Reservation/allocation never changes on-hand; Available = UNRESTRICTED on-hand − active allocations. | Inventory | SVC | D-014 | allocation test |
| BR-INV-09 | Only `UNRESTRICTED` stock can be allocated, issued or shipped. | Inventory | SVC | D-014 | #6, #7 |
| BR-INV-10 | Quantities `NUMERIC(20,6)`; discrete items enforce integer scale; UOM and conversion version mandatory. | Inventory | DB | doc 04 | unit tests |
| BR-INV-11 | Projection equals Σ ledger; nightly reconciliation alerts on difference. | Inventory | job | ADR-0002 | rebuild test |

## Periods (PER)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-PER-01 | `effective_date` must be in an OPEN period for the plant. | Inventory | SVC | D-015 | #17 |
| BR-PER-02 | Closed-period correction posts in current open period with `correction_of_document_id`, original date, reason, evidence, maker-checker. | Inventory + Core approval | SVC + POL | D-015 | #17 |
| BR-PER-03 | Period reopen requires Company Super Admin request + independent approver; logged as exceptional event. | Core | POL | D-015 | reopen test |
| BR-PER-04 | Postings record `production_date` and `shift_id` from tenant shift calendar. | Production/Inventory | SVC | DD-08 | shift cutoff test |

## Demand (DEM)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-DEM-01 | Customer PO and supplier PO are distinct entities and numbering series. | Demand | DB | doc 00 | — |
| BR-DEM-02 | PO revisions are versioned; released WOs referencing superseded lines raise PPIC notification, never silently change. | Demand/Planning | SVC + outbox | doc 01 | revision test |
| BR-DEM-03 | Net demand never double counts: forecast quantity is consumed by firm demand (BR-DEM-08) in the same bucket; kanban/delivery release is a Delivery Schedule Line of an SO line (BR-DEM-06), not additional demand. Bucket = **weekly** for MPS netting with daily requirement dates kept (D-041, BR-DEM-13). | Demand | SVC | D-004, D-041 | #8, #30, #31, #47 |
| BR-DEM-04 | Delivered quantity reduces outstanding only via posted shipment lines (formula BR-DEM-10). | Delivery | SVC | doc 01 | #18, #32 |
| BR-DEM-05 | **Demand identity (D-034):** each Sales Order line has exactly one primary demand identity (`demand_id`). A Customer PO line is a source document mapped to **exactly one** SO line (unique mapping) and is never netted on its own. | Demand | SVC + DB unique mapping | D-030, D-034 | #28 |
| BR-DEM-06 | **Schedule lines (D-034):** an SO line has one or more Delivery Schedule Lines / Customer Releases (`schedule_type` CUSTOMER_SCHEDULE \| KANBAN_RELEASE \| MANUAL). Each carries: source document type + number + line reference, requirement date, requirement quantity + UOM (= SO line UOM), revision number, allocation reference(s), fulfilment reference(s), status (doc 16 §12). Uniqueness: (`so_line`, `source_document`, `source_line_ref`, `revision`) — a re-imported schedule is idempotent, never a second demand. | Demand | SVC + DB unique | D-034 | #29, #37 |
| BR-DEM-07 | **Firm demand quantity:** **firm outstanding** of an item in a bucket = Σ **outstanding** quantity (BR-DEM-10) of schedule lines in status `FIRM` or `PARTIALLY_FULFILLED` whose requirement date falls in the bucket. `DRAFT`, `SUPERSEDED`, `CANCELLED` and `FULFILLED` lines count zero. Customer PO lines, SO line ordered quantity and planning requirement records are never added on top. Blanket quantity without a confirmed release is **Unscheduled Blanket Balance** (BR-DEM-14) and is not firm demand in any bucket (D-039). | Demand/Planning | SVC | D-034, D-039 | #28, #31, #43 |
| BR-DEM-08 | **Forecast consumption (D-040):** firm demand consumes forecast only **within the same bucket** and only when **customer + part + plant** match and the forecast line carries a valid reference (forecast version, customer); never by part number alone. `firm_required = Σ required qty` of matched schedule lines not `DRAFT`/`SUPERSEDED`/`CANCELLED` (fulfilled lines included, so delivery never re-opens forecast); `net_forecast = max(0, forecast − firm_required)`; **outstanding demand for planning** = `firm_outstanding (BR-DEM-07) + net_forecast`. Firm demand has priority. Consumption is recorded in `demand_consumptions` (forecast line ↔ schedule line, qty) and recomputed on every demand change. When a match cannot be established (e.g. forecast without customer, part used by several customers) the forecast stays **unconsumed** and a `FORECAST_MATCH_UNCERTAIN` demand exception is raised for PPIC; no forward/backward window in R1 (advanced algorithms → R2). | Demand | SVC | D-034, D-040 | #30, #34, #45, #46 |
| BR-DEM-09 | **Matching:** an allocation or a shipment line always references **one** schedule line (fulfilment reference). Allocation reserves FG (DD-03) but is **not** fulfilment; only a posted shipment line fulfils (BR-DEM-04). A shipment for an SO line without a matching active schedule line is rejected or routed to an exception with reason. | Demand/Delivery | SVC | D-034, DD-03 | #32, #33 |
| BR-DEM-10 | **Outstanding:** `delivered = Σ posted shipment qty − Σ posted shipment reversals` per schedule line; `outstanding = max(0, required − delivered)`; `over_delivered = max(0, delivered − required)`. SO line outstanding = Σ schedule-line outstanding (+ unscheduled, shown separately). Allocated quantity never changes outstanding. Status `PARTIALLY_FULFILLED` when 0 < delivered < required; `FULFILLED` when delivered ≥ required. Over-delivery can only arise within tenant over-shipment tolerance (BR-FG-03) or after a downward revision (BR-DEM-11); it is shown, never netted as negative demand. | Demand/Delivery | SVC | D-034, BR-DEM-04 | #32, #34, #35 |
| BR-DEM-11 | **Revision (D-038):** a Customer PO / SO / schedule revision creates new schedule lines with `revision + 1`; replaced lines become `SUPERSEDED` (kept, read-only) and their **outstanding** counts zero. Posted shipments keep their original schedule-line reference and revision; delivered quantity is **never moved or rewritten** automatically. Only outstanding quantity may be re-allocated to new lines, through an explicit `schedule_reallocations` mapping (from line, to line, qty, reason, actor, timestamp) that is validated (Σ re-allocated ≤ outstanding of the source; same SO line; same UOM) and audited. If the revision cannot be mapped unambiguously (e.g. split or merged dates, changed part) the system requires user confirmation or opens a `REVISION_MAPPING_AMBIGUOUS` demand exception; total outstanding changes only through a valid transaction or approved revision. A revision that sets required below delivered keeps delivered, sets outstanding 0 and flags over-delivery. Shipment corrections use the reversal workflow (BR-DEM-10). Released WOs, allocations or MPS lines pegged to superseded lines raise a PPIC notification (BR-DEM-02). | Demand/Planning | SVC + outbox + audit | D-034, D-038, BR-DEM-02 | #36, #40, #41, #42 |
| BR-DEM-12 | **Planning requirement records are derived:** MPS requirement records per item/date/bucket peg to `demand_id` + schedule line (or forecast line for net forecast) and are recomputed per MPS version; they are never a demand source, never re-imported as demand and unique per (`mps_version`, `item`, `bucket`, `pegged_line`). | Planning | SVC + DB unique | D-034, D-031 | #38, #39 |
| BR-DEM-13 | **Buckets and calendar (D-041):** requirement dates are stored as actual dates (plant calendar, Asia/Jakarta). MPS netting aggregates into **weekly** buckets whose start is taken from the plant business-calendar configuration (proposed default ISO week, Monday 00:00 WIB); a schedule line belongs to exactly one bucket. Delivery schedules and daily delivery monitoring remain daily; a weekly total never replaces the daily due view, and a daily shortfall inside a covered week is still reported as risk. Monthly = optional dashboard aggregation. | Demand/Planning | SVC + config | D-041 | #47, #48 |
| BR-DEM-14 | **Blanket SO quantities (D-039):** per blanket SO line — `blanket_qty` (authorized, revisioned); `released_qty = Σ required qty of confirmed releases` (status FIRM / PARTIALLY_FULFILLED / FULFILLED); `delivered_qty = Σ delivered` (BR-DEM-10); `remaining_scheduled_qty = Σ outstanding of confirmed releases`; `unscheduled_blanket_balance = blanket_qty − released_qty`. Guard: `released_qty ≤ blanket_qty` unless an approved order revision raises `blanket_qty`; a release that would exceed it is rejected. Delivered quantity is never shown as remaining demand. | Demand | SVC + DB check | D-039 | #43, #44 |

## Planning / WO (PLN)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-PLN-01 | WO release snapshots BOM, routing, conversion profile (`CP`), standard yield (`SY`) and net weight (`NW`) versions. | Planning | SVC | doc 01, ADR-0005 | snapshot test |
| BR-PLN-02 | RM → estimated pcs is a planning estimate, never a ledger movement; shown with `ConversionBadge` status. | Planning | SVC/UI | doc 02 | #5 |
| BR-PLN-04 *(PROPOSED)* | MPS is versioned per plant and horizon (daily/weekly buckets); only an **approved** MPS version can release WOs; revisions create a new version with approval history. | Planning | SVC + POL | D-031 | MPS version test |
| BR-PLN-05 *(PROPOSED)* | Net production requirement per item/bucket = demand (netted per BR-DEM-03/05) − FG unrestricted available − QC released − scheduled supply (open WOs/planned orders) + safety stock policy; Pending QC excluded unless tenant policy says otherwise. Never posts inventory. | Planning | SVC | D-031, D-014 | netting golden test |
| BR-PLN-06 *(PROPOSED)* | Basic MRP explodes planned orders through the effective BOM into material requirements in material UOM and compares with available material (unrestricted − allocations), never with estimated pcs. | Planning | SVC | D-031, doc 02 | MRP test |
| BR-PLN-07 *(PROPOSED)* | Planned production orders are planning records; converting to a WO is an explicit release action with snapshot (BR-PLN-01). Basic capacity check warns, does not optimise. | Planning | SVC | D-031 | release test |
| BR-PLN-03 | WO close requires: no open runs, no Material at Production allocated to the WO (returned, transferred, or approved exception), reconciliation indicators FINAL or case opened. *(exception rule OPEN O-015)* | Planning | SVC | O-015 | close test |

## Material issue, custody, return (ISS)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-ISS-01 | Issue is a transfer `RM_WAREHOUSE → MAT_AT_PROD(work center)`; consumption remains zero until posted. | Materials → Inventory | SVC | D-013 | #1 |
| BR-ISS-02 | Material at Production is keyed by work center + lot; WO linkage is an allocation, not a location. | Inventory | DB/SVC | D-013 | multi-WO test |
| BR-ISS-03 | When tenant enables two-step custody, issue lands in `CUSTODY_TRANSIT`; Production acceptance moves it to `MAT_AT_PROD`; partial acceptance creates a discrepancy case. | Materials/Production | SVC | D-013 | custody test |
| BR-ISS-04 | Only lots with status `UNRESTRICTED` and approved spec can be issued; substitution requires approved substitution record (scope: item, customer, WO). | Materials | SVC + POL | doc 01 | #9 |
| BR-ISS-05 | Return `MAT_AT_PROD → RM_WAREHOUSE` requires measured quantity (weight/count). | Materials | SVC | D-010 | return test |
| BR-ISS-06 | RM receipt for items requiring incoming release posts as `BLOCKED` until released. *(scope OPEN O-013)* | Materials/Quality | SVC | O-013 | — |

## Consumption (CON)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-CON-01 | Every consumption line has `measurement_method` and evidence (ADR-0003). | Production | SVC | D-010 | — |
| BR-CON-02 | `UNMEASURED_BY_DIFFERENCE` consumption is PROVISIONAL, opens a case, and blocks FINAL indicators. | Production/Traceability | SVC | D-010 | by-difference test |
| BR-CON-03 | Consumption cannot exceed available quantity of that lot at that work center. | Inventory | SVC | D-015 | over-consume test |
| BR-CON-04 | Shared lot across WOs: consumed quantity allocated once per run; theoretical outputs of alternative parts never summed. | Production | SVC | doc 02 | multi-WO coil test |

## Output (OUT)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-OUT-01 | Input material deducted once (consume leg); outputs are produce legs; no category adds back or re-deducts input. | Production/Inventory | SVC | D-011 | #19 |
| BR-OUT-02 | Good Output → `QC_AREA/PENDING_QC` when inspection required, else `UNRESTRICTED` at next process. | Production | SVC | D-014 | #6 |
| BR-OUT-03 | NG Reworkable → `NG_AREA/REWORK`; NG Rejected → `NG_AREA/REJECTED`; both in pcs of the produced item. | Production | SVC | D-011 | #20 |
| BR-OUT-04 | Process Scrap posted in measured kg to scrap item per material grade with reason code. | Production | SVC | D-011 | #21 |
| BR-OUT-05 | Reusable Remnant = lot split to child lot with measured qty and dimensions; conserves quantity. | Production/Materials | SVC | DD-04 | #22 |
| BR-OUT-06 | Rework Order consumes `REWORK` pcs and produces Good (→ PENDING_QC) and/or Rejected; genealogy preserved. | Production/Quality | SVC | D-011 | rework test |
| BR-OUT-07 | Over-reporting guard: if reported pcs exceed `T` by more than tenant guard (default 0%), posting requires supervisor confirmation. | Production | SVC | doc 02 | over-report test |

## Quality & FG (QC / FG)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-QC-01 | Uninspected quantity cannot be released; partial disposition splits the inspection lot. | Quality | SVC | doc 01 | #6 |
| BR-QC-02 | QC disposition outcomes: `PENDING_QC → QC_RELEASED / HOLD / REWORK / REJECTED`; `HOLD → PENDING_QC / REJECTED`. | Quality | SVC | D-014 | state tests |
| BR-QC-03 | The person who reported production output cannot release QC for the same lot (SoD). | Core/Quality | POL | doc 05 | SoD test |
| BR-FG-01 | FG Receipt is a separate transaction `QC_AREA/QC_RELEASED → FG_WAREHOUSE/UNRESTRICTED`; QC accept alone never changes FG stock. | Warehouse/Inventory | SVC | D-014 | #7 |
| BR-FG-02 | Only `UNRESTRICTED` FG minus allocations is available for picking/shipment. | Warehouse | SVC | D-014 | delivery concurrency |
| BR-FG-03 | Shipment posts `FG/STAGING → V_CUSTOMER` against Delivery Schedule Lines of SO lines (BR-DEM-09, D-034); partial shipments allowed; over-shipment blocked unless tenant tolerance. | Delivery | SVC | doc 01 | #18 |

## Reconciliation & variance (REC)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-REC-01 | Three indicators GYV, PvS, UMV calculated per ADR-0005 with status NOT_CALCULABLE/PROVISIONAL/FINAL. | Traceability (Reconciliation) | SVC | D-012 | #2–#5, #23 |
| BR-REC-02 | Three distinct terms (D-024): **Unexplained Material Variance** (calculated indicator, never called loss); **Actual Material Loss** (only when an investigation proves physical loss — evidence, approval, stock adjustment, case resolution `ACTUAL_MATERIAL_LOSS_CONFIRMED`); **Physical Stock Discrepancy** (counted minus book from an approved stock count, resolution `PHYSICAL_DISCREPANCY_CONFIRMED`). Menus and general indicators use "Material Variance". | All UI/reports | UI + report templates review | doc 02, D-024 | copy review |
| BR-REC-03 | Tolerance breach (abs AND %) on FINAL indicator opens case; without configuration strict default *(OPEN O-012)*. | Traceability | SVC | ADR-0005 | tolerance test |
| BR-REC-04 | Case cannot be closed without FINAL indicator or approved exception; resolution by cause code, optional adjustment with maker-checker. | Traceability/Inventory | SVC + POL | doc 02 | case test |
| BR-REC-05 | Reversal of an included document reopens indicators to PROVISIONAL with versioned history. | Traceability | SVC | ADR-0005 | reversal test |

## Stock count (CNT)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-CNT-01 | Count uses snapshot at cut-off per location; movements after snapshot tracked as deltas *(freeze vs snapshot OPEN O-006)*. | Inventory | SVC | O-006 | count cutoff test |
| BR-CNT-02 | Blind count option hides book quantity from counter. | Inventory | UI + API field exclusion | O-014 | — |
| BR-CNT-03 | Counter cannot approve own count variance; adjustment posts via ledger with reason/evidence. | Inventory/Core | POL | doc 05 | SoD test |

## Approval, audit, notification, exchange (APR / AUD / NTF / XCH)
| ID | Rule | Owner | Enforce | Source | Test |
|---|---|---|---|---|---|
| BR-APR-01 | Maker ≠ checker for adjustments, reversals, period reopen, substitutions, count variances above threshold. | Core | POL | D-007 | SoD tests |
| BR-APR-02 | Approval from push/notification opens review screen; never one-tap execution; step-up auth for high-risk. | Core | UI + POL | doc 07 | #14 |
| BR-AUD-01 | Every posting, approval, permission change, export, import, login/tenant switch emits immutable audit event. | Core | SVC | doc 05 | audit test |
| BR-NTF-01 | Notifications from outbox; one logical inbox item per recipient per event; permission re-checked on open. | Core | SVC | doc 07 | #14 |
| BR-XCH-01 | Export uses the same QueryDefinition and scope as grid. | Core | SVC | ADR-0008 | #13 |
| BR-XCH-02 | Import posts through domain services with idempotency; never direct ledger writes. | Core | SVC | doc 08 | #15 |

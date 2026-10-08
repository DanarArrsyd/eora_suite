# ADR-0002 — Inventory ledger, stock status, locations and periods

- **Status:** Accepted (D-003, D-013, D-014, D-015, DD-01..DD-03, DD-08) · **Date:** 2026-10-08

## Context
All modules show quantities, but only Inventory may change balances (D-003). Issue ≠ consumption (D-013); Pending QC ≠ FG (D-014); negative stock and closed-period edits prohibited (D-015).

## Decision
### 1. Balance dimensions
A stock balance key is `(tenant_id, plant_id, location_id, item_id, lot_id, stock_status, ownership, uom_id)`. Status, location and ownership are independent dimensions.

### 2. Stock status catalogue (DD-01)
| Status | Meaning | Usable for issue/ship? |
|---|---|---|
| `UNRESTRICTED` | Physically present, released for normal use | Yes (subject to allocation) |
| `PENDING_QC` | Output awaiting inspection | No |
| `QC_RELEASED` | QC accepted, not yet received into FG warehouse | No (only FG Receipt) |
| `HOLD` | Quarantined pending decision | No |
| `REWORK` | NG reworkable, awaiting/under rework | No (only Rework Order) |
| `REJECTED` | NG rejected, awaiting scrap/return disposition | No |
| `BLOCKED` | Administrative block (e.g. RM incoming not released, count freeze) | No |
Ownership: `OWN` in V1; `CUSTOMER_OWNED`, `CONSIGNMENT` reserved (O-005).

### 3. Location types
Physical: `RM_WAREHOUSE`, `MAT_AT_PROD` (one per work center), `CUSTODY_TRANSIT` (only when two-step custody enabled), `QC_AREA`, `NG_AREA`, `SCRAP_YARD`, `FG_WAREHOUSE` (bins), `STAGING`.
Virtual (never hold balances, used as ledger counterparties): `V_SUPPLIER`, `V_CUSTOMER`, `V_CONSUMED`, `V_PRODUCED`, `V_ADJUSTMENT`, `V_OPENING`.

### 4. Ledger structure (DD-02)
- `inventory_documents` (header: type, number, status, effective/production date, shift, idempotency key, version, `reverses_document_id`).
- `inventory_movements` (lines/legs): signed quantity per balance key. Append-only; no UPDATE/DELETE grants for the application role after posting.
- **Conservation rule:** for every non-transformation document, Σ signed qty per `(item, lot, uom)` = 0 across all legs, counting virtual locations. Transformation documents (Production Posting, Scrap Disposition) consume one item into `V_CONSUMED` and produce other items from `V_PRODUCED`; their correctness is checked by the variance indicators (ADR-0005), not by Σ = 0.
- `stock_balances` projection updated in the **same DB transaction** as the legs. Rebuildable from the ledger; nightly job compares projection vs Σ ledger and alerts on any difference.
- Reservation/allocation (DD-03): `stock_allocations` references a balance key and a demand (WO line, shipment line). It reduces *available*, never *on-hand*. Available = on-hand(UNRESTRICTED) − active allocations.

### 5. Posting rules
- **Negative stock prohibited** for every physical location and status. Validation under row lock: `SELECT … FOR UPDATE` on the affected `stock_balances` rows, **locked in deterministic key order** to avoid deadlocks; check `qty + delta ≥ 0`; then insert legs and update projections. Postings retry on serialization/deadlock errors (bounded, idempotent).
- **Idempotency:** client-generated `Idempotency-Key` stored with `UNIQUE (tenant_id, idempotency_key)`; replay returns the original result.
- **Optimistic concurrency** for drafts: `version` column, `If-Match` header.
- **Status change = movement** (same location, two statuses) so status history is in the ledger.
- **Reversal:** a new document with legs negated, linked by `reverses_document_id`; original untouched. Partial reversal not allowed — reverse and repost the corrected document.

### 6. Periods (D-015)
`inventory_periods (tenant_id, plant_id, period, status OPEN|CLOSING|CLOSED)`. A posting's `effective_date` must fall in an `OPEN` period. Corrections for closed periods are posted in the current open period with `correction_of_document_id`, `original_effective_date`, reason, evidence and maker-checker approval. Reopening a closed period requires Company Super Admin request + independent approver + audit; it is an exceptional, logged operation.

### 7. Time
`posted_at` UTC instant; `effective_date` and `production_date` are tenant-local dates; `shift_id` stored explicitly, derived from the shift calendar at posting (DD-08).

## Consequences
+ Every quantity on any screen reconciles to legs; projections are disposable.
+ Status/location history auditable without separate history tables.
− More rows per business event (2+ legs); partitioning of `inventory_movements` by month is planned when volume justifies (doc 20).
− Hot lots serialize on one balance row; acceptable at D-019 load, measured in doc 20 scenario W-07.

## Verification
Property tests for conservation; concurrent issue of same lot (doc 14 #10); negative-stock rejection; projection rebuild equality; reversal leaves history intact; closed-period posting rejected and correction path works (doc 14 #17).

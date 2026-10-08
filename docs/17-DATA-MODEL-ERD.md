# 17 — Conceptual ERD and Logical Data Model

Logical model only — **not a migration**. Physical types, indexes and partitioning are finalized at Foundation after approval. All tenant-owned tables follow ADR-0001: `id` (UUIDv7/ULID) PK, `tenant_id NOT NULL`, `UNIQUE (tenant_id, id)`, composite FKs `(tenant_id, x_id)`, RLS enabled and forced, audit columns `created_at, created_by, updated_at, updated_by, version`.

## 1. Conceptual ERD (domains)
```mermaid
erDiagram
  TENANT ||--o{ COMPANY : owns
  COMPANY ||--o{ PLANT : operates
  PLANT ||--o{ WAREHOUSE : has
  WAREHOUSE ||--o{ LOCATION : has
  PLANT ||--o{ WORK_CENTER : has
  WORK_CENTER ||--|| LOCATION : "MAT_AT_PROD location"
  TENANT ||--o{ MEMBERSHIP : grants
  USER ||--o{ MEMBERSHIP : holds
  MEMBERSHIP ||--o{ ROLE_ASSIGNMENT : scoped
  CUSTOMER ||--o{ CUSTOMER_PO : issues
  CUSTOMER_PO ||--o{ CUSTOMER_PO_LINE : contains
  CUSTOMER_PO_LINE ||--|| SO_LINE : "mapped to (D-034)"
  SALES_ORDER ||--o{ SO_LINE : contains
  SO_LINE ||--o{ DELIVERY_SCHEDULE_LINE : "scheduled / called off by"
  FORECAST ||--o{ DEMAND_CONSUMPTION : "consumed by"
  DELIVERY_SCHEDULE_LINE ||--o{ DEMAND_CONSUMPTION : consumes
  ITEM ||--o{ BOM_VERSION : has
  ITEM ||--o{ ROUTING_VERSION : has
  ITEM ||--o{ CONVERSION_PROFILE : has
  WORK_ORDER }o--|| ITEM : produces
  WORK_ORDER ||--o{ WO_DEMAND_LINK : fulfils
  WORK_ORDER ||--o{ PRODUCTION_RUN : "executed by"
  ITEM ||--o{ LOT : "tracked as"
  LOT ||--o{ LOT : "split into (remnant)"
  INVENTORY_DOCUMENT ||--|{ INVENTORY_MOVEMENT : "has legs"
  INVENTORY_MOVEMENT }o--|| LOT : moves
  INVENTORY_MOVEMENT }o--|| LOCATION : at
  STOCK_BALANCE }o--|| LOT : projects
  STOCK_ALLOCATION }o--|| STOCK_BALANCE : reserves
  PRODUCTION_RUN ||--o{ INVENTORY_DOCUMENT : "posts (issue/posting/return)"
  INVENTORY_DOCUMENT ||--o{ GENEALOGY_EDGE : creates
  GENEALOGY_EDGE }o--|| LOT : from
  GENEALOGY_EDGE }o--|| LOT : to
  INSPECTION_LOT }o--|| LOT : inspects
  INSPECTION_LOT ||--o{ QC_DISPOSITION : has
  SHIPMENT ||--|{ SHIPMENT_LINE : contains
  SHIPMENT_LINE }o--|| DELIVERY_SCHEDULE_LINE : fulfils
  RECON_SCOPE ||--o{ VARIANCE_RESULT : calculates
  VARIANCE_RESULT ||--o| RECON_CASE : triggers
  RECON_CASE ||--o{ APPROVAL_REQUEST : needs
  INVENTORY_DOCUMENT ||--o{ OUTBOX_EVENT : emits
  OUTBOX_EVENT ||--o{ INBOX_NOTIFICATION : "fans out to"
```

## 2. Logical tables by domain

### Core / tenancy / security
| Table | Key columns | Notes |
|---|---|---|
| `tenants` | id, code, status, plan, timezone (default Asia/Jakarta), data_region | **not** RLS-scoped; platform table |
| `companies` | tenant_id, id, legal_name | |
| `plants` | tenant_id, id, company_id, code, timezone, shift_calendar_id | |
| `warehouses`, `locations` | tenant_id, id, plant_id, `location_type` (ADR-0002 §3), work_center_id nullable | virtual locations seeded per plant |
| `work_centers`, `machines`, `dies` | tenant_id, id, plant_id, die stroke counters | |
| `users` | id, email, mfa | global identity |
| `memberships` | tenant_id, id, user_id, status | user ↔ tenant |
| `roles`, `permissions`, `role_permissions` | tenant_id (roles), permission key | permission catalogue global |
| `role_assignments` | tenant_id, membership_id, role_id, scope_type (`company/plant/warehouse/work_center`), scope_id | data scope (doc 18) |
| `approval_policies`, `approval_requests`, `approval_steps` | tenant_id, entity_type, action, threshold, approver_role, sod_rule | |
| `audit_events` | tenant_id, id, occurred_at, actor, action, entity, before/after hash, correlation_id | append-only |
| `inventory_periods` | tenant_id, plant_id, period, status | |
| `shift_calendars`, `shifts` | tenant_id, start/end local time, crosses_midnight | `production_date` rule |

### Master data
`items (type: RAW, SEMI_FINISHED, FINISHED, SCRAP, CONSUMABLE; base_uom; lot_controlled; inspection_required)`, `uoms`, `uom_conversions (item-specific, versioned)`, `material_specs (grade, thickness_mm, width_mm, density_g_cm3, nominal)`, `bom_versions/lines`, `routing_versions/operations (standard_yield SY, version, effective_from)`, `item_weights (NW, version)`, `conversion_profiles (item_id, input_form SHEET|COIL, inputs JSONB validated by formula_code schema, status DRAFT|VALIDATED|RETIRED, version, effective_from, validated_by)`, `customers`, `customer_items (customer part ↔ item)`.

### Demand
`sales_order_headers (customer, status, revision)`, `sales_order_lines (demand_id, item, ordered_qty, uom, status derived, revision)`, `po_so_links (customer_po_line → so_line, UNIQUE per po_line)` — D-030/D-034 (logical only; no migration).
`delivery_schedule_lines (so_line, schedule_type CUSTOMER_SCHEDULE|KANBAN_RELEASE|MANUAL, source_document_type, source_document_no, source_line_ref, requirement_date, required_qty, uom, revision, status, superseded_by)` — UNIQUE (so_line, source_document_no, source_line_ref, revision); replaces the former `demand_releases → po_line` (D-034). Fulfilment via `shipment_lines.schedule_line_id` (immutable after posting, D-038); allocation via `stock_allocations.demand_type = SCHEDULE_LINE`. `schedule_reallocations (from_line, to_line, qty, uom, reason, actor, created_at, revision)` — audited mapping of outstanding only (D-038). `demand_exceptions (type REVISION_MAPPING_AMBIGUOUS|FORECAST_MATCH_UNCERTAIN|BLANKET_EXCEEDED, refs, status, owner)`. Blanket SO line keeps `blanket_qty` with revision history (D-039). `business_calendars (plant, timezone, week_start, shift calendar)` drives buckets (D-041). All logical only; no migration.
`customer_po_headers (revision)`, `customer_po_lines (qty, due_date, status, revision)` — source documents only, never netted. `forecasts (item, bucket_start, qty, version)`, `demand_consumptions (forecast_id ↔ schedule_line_id, qty)` — BR-DEM-08.

### Planning / Production
`mps_versions (plant, horizon, bucket, status DRAFT|SUBMITTED|APPROVED|SUPERSEDED)`, `mps_lines (item, bucket, demand, available, scheduled_supply, net_requirement)`, `mps_requirement_pegs (mps_line → schedule_line | forecast line, qty; UNIQUE per mps_version+item+bucket+pegged line, BR-DEM-12)`, `planned_orders (mps_line, qty, due, status)`, `material_requirements (planned_order, material item, qty material UOM)` — added by D-031 (logical only; no migration).
`work_orders (item, qty, status, snapshot ids: bom_version, routing_version, conversion_profile_version, sy_version, nw_version)`, `wo_demand_links`, `production_runs (wo, work_center, machine, die, shift_id, production_date, operator, status, strokes_start/end)`, `run_measurements (run, lot, method, scale_id, gross, tare, net, counted_qty, evidence_file)`.

### Inventory ledger (authoritative)
| Table | Columns | Constraints |
|---|---|---|
| `lots` | tenant_id, id, item_id, lot_no, parent_lot_id, origin (`RECEIPT/OUTPUT/SPLIT`), actual properties JSONB (mill cert thickness/width/weight), status flags | `UNIQUE (tenant_id, item_id, lot_no)` |
| `inventory_documents` | tenant_id, id, doc_type (`RM_RECEIPT, MAT_ISSUE, CUSTODY_ACCEPT, MAT_RETURN, LOT_SPLIT, PRODUCTION_POSTING, QC_DISPOSITION, FG_RECEIPT, PICK, SHIPMENT, SCRAP_DISPOSITION, REWORK_POSTING, STOCK_ADJUSTMENT, REVERSAL`), doc_no, status, plant_id, effective_date, production_date, shift_id, posted_at, posted_by, idempotency_key, reverses_document_id, correction_of_document_id, source_ref (type,id) | `UNIQUE (tenant_id, idempotency_key)`, `UNIQUE (tenant_id, doc_type, doc_no)` |
| `inventory_movements` | tenant_id, id, document_id, leg_no, plant_id, location_id, item_id, lot_id, stock_status, ownership, uom_id, qty_signed NUMERIC(20,6), measurement_method, run_id, wo_id | append-only (no UPDATE/DELETE grant); partition by month (when volume justifies) |
| `stock_balances` | tenant_id, plant_id, location_id, item_id, lot_id, stock_status, ownership, uom_id, qty, version | PK = balance key; `CHECK (qty >= 0)`; physical locations only |
| `stock_allocations` | tenant_id, id, balance key, demand_type (`WO`,`SCHEDULE_LINE`), demand_id, qty, status | Σ active ≤ on-hand (service-enforced under lock) |
| `genealogy_edges` | tenant_id, id, from_lot_id, to_lot_id, document_id, run_id, qty_from, uom_from, qty_to, uom_to | multi-lot, multi-WO |
| `stock_counts`, `stock_count_lines` | snapshot qty, counted qty, blind flag | |

### Quality / Warehouse / Delivery
`inspection_lots (lot_id, qty, status)`, `qc_dispositions (inspection_lot_id, outcome, qty, defect_code, document_id)`, `handling_units`, `pick_lists`, `delivery_orders`, `shipments`, `shipment_lines (po_line_id, release_id, qty, document_id)`.

### Production reconciliation
| Table | Columns | Notes |
|---|---|---|
| `recon_scopes` | tenant_id, id, scope_type (`RUN, WO, LOT`), scope_id, status | |
| `variance_results` | tenant_id, id, scope_id, indicator (`GYV, PVS, UMV`), calc_version, status (`NOT_CALCULABLE, PROVISIONAL, FINAL`), value NUMERIC(20,6), value_pct, unit, inputs_snapshot JSONB (C, T unrounded, G, NR, NJ, O, S, D, CP/SY/NW versions), missing_inputs[], tolerance_applied, breach, calculated_at | append new version, never update value in place |
| `recon_cases` | tenant_id, id, case_type (`VARIANCE_BREACH, UNMEASURED_CONSUMPTION, CUSTODY_DISCREPANCY, COUNT_VARIANCE`), scope_id, status (doc 16 §7), owner, due_date, cause_code, resolution_type | |
| `recon_case_evidence` | case_id, file_id, measurement refs | |

### Eventing / exchange
`outbox_events (tenant_id, id, aggregate_type, aggregate_id, event_type, payload, occurred_at, dispatched_at, attempts)`, `processed_events (consumer, event_id)`, `inbox_notifications (tenant_id, user_id, event_id, severity, action_required, deep_link, read_at, UNIQUE(user_id,event_id))`, `push_subscriptions`, `import_jobs/import_rows`, `export_jobs`, `documents/files (storage_key tenant-prefixed, sha256)`.

### Read models (rebuildable, Analytics-owned)
`rm_conversion_view (per lot: on-hand, CP status, est pcs range)`, `pending_qc_aging`, `daily_fg_receipt (production_date, shift)`, `daily_delivery_outstanding`, `material_variance_summary`, `flow_strip_snapshot (scope, stage, qty, uom, as_of)`. Each row carries `as_of` and source high-water mark (last ledger movement id).

## 3. Tenant isolation checklist (per table)
1. `tenant_id NOT NULL`, leading column of every index used by queries.
2. Composite FK to every tenant parent.
3. `ENABLE` + `FORCE ROW LEVEL SECURITY` with `tenant_id = current_setting('app.tenant_id', true)::uuid` for USING and WITH CHECK.
4. Application role: no table ownership, no BYPASSRLS; ledger tables without UPDATE/DELETE.
5. Included in automated isolation suite (ADR-0001 T-ISO-01..05).

## 4. Genealogy traversal
Backward trace (shipment → material): `shipment_lines.document_id` → movements(lot) → `genealogy_edges` (to_lot = FG lot) recursively → RM lot → receipt document → supplier ref. Forward trace inverse. Implemented as recursive CTE with depth limit and tenant filter; materialized closure table only if performance tests (doc 20 W-09) require.

## 5. Open physical questions (carry from doc 04)
Serial tracking (V2), semi-finished part numbering (O-003), customer-owned/consignment (O-005), retention and data residency (O-008).

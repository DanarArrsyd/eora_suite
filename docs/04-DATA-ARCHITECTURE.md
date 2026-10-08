# 04 — Data Architecture, ERD Requirements & Ledger

## Entities (logical; physical schema to be designed)
`tenants, companies, plants, departments, warehouses, locations, users, memberships, roles, permission_grants, approval_requests, audit_events`; `customers, customer_po_headers/lines, sales_orders, demand_releases, forecasts`; `items, item_categories, uoms, uom_conversions, material_specs, bom_versions/lines, routing_versions/operations, machines, dies, shifts`; `work_orders, production_runs, material_lots, material_reservations, material_issue_lines, consumption_events, material_returns, remnant_records, scrap_events, output_lots`; `inspection_lots/results/dispositions`; `inventory_movements, stock_balance_projections, stock_counts, adjustments, period_closes`; `handling_units, putaways, pick_lists, packing_records, shipments/lines`; `genealogy_edges, reconciliation_cases, outbox_events, inbox_notifications, push_subscriptions, import_jobs, export_jobs, documents`.

## Keys and invariants
- Tenant-aware unique constraints, foreign keys and indexed scope columns. Tenant boundary in all queries, jobs, exports and object-storage paths. Evaluate PostgreSQL RLS as defense in depth but do not rely on it as the only tenant control.
- Quantities as exact decimal/numeric with defined precision; never binary floating point for stock. Units and conversion version required. Integer counts where physically discrete; measured kg/length exact decimal.
- Movement ledger append-only for posted movements; reverse with compensating movement, never edit posted history. Atomic stock balance projection update in same transaction; reconcile projection periodically to ledger.
- Status/ownership/location are distinct dimensions; transfers preserve quantity conservation; issue to Production is a location/custody transfer, not automatic consumption.
- Optimistic versions for documents; locks for stock-critical postings; idempotency keys and unique posting references; outbox inserted in same DB transaction.
- Every genealogy link references actual input/output lot and WO/run/operation; multi-lot and multi-WO allocations supported.
- Date/time: store UTC instants, display tenant timezone; define shift production date and cutoff separately. No silent retrospective posting to closed periods.

## Data products and query models
Operational ledger and OLTP records authoritative. Derived stock projections, daily aggregates and dashboards are rebuildable. Read replicas/reporting store only if measurements justify. Report `as_of`, timezone, freshness, and filter scope.

## Open physical design questions
Item vs material lot granularity; serial tracking; coil/remnant ID lineage; intermediate material item naming; customer-owned/consignment stock; QC physical ownership and storage; yield version effective dates; stock count freeze vs snapshot; retention and data residency. Resolve via ADR before migrations.

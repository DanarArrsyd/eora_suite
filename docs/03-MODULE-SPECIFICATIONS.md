# 03 — Module Specifications & Boundaries

| Domain | V1 minimum | Owns | Does NOT own |
|---|---|---|---|
| Core | Tenant/org/user/RBAC, approval, audit, notification, document, correction | Identity and platform policy | Operational stock |
| Demand | Customer PO, Sales Order, forecast, Kanban/release, revisions, fulfillment references | Customer demand | Inventory balance |
| Planning | BOM/routing versions, requirements, capacity checks, WO, PPIC Control Tower | WO and planning snapshot | QC disposition |
| Materials | Receiving reference, lot, reservation, issue/return, substitution approvals, custody | Material documents | Independent stock ledger |
| Production | Runs, machine/dies/shift, actual consumption request, good/NG, downtime, process scrap | Production facts | Final FG availability |
| Quality | Inspection, partial disposition, Hold/Rework/Accepted/Rejected | QC decisions | Warehouse putaway |
| Inventory | Movement ledger, stock balance projection, status/location transfer, stock count, period close | All inventory balances | Production run facts |
| Warehouse | Bin, handling unit, FG receipt request, putaway, picking, packing | Physical warehouse tasks | Independent balances |
| Delivery | Delivery order, partial shipment, dispatch confirmation | Shipment facts | Customer demand creation |
| Maintenance | Assets, dies, availability, breakdown, basic PM/stroke checks | Asset state | Advanced predictive IoT |
| Traceability | Lot genealogy and forward/backward links | Genealogy graph/read models | Duplicate source transactions |
| Analytics | Role KPIs, exception boards, daily summaries, export | Read models/report definitions | Writable operational truth |

## PPIC Control Tower screens
Overview; RM Coil/Sheet Availability; RM → Estimated PCS; Material at Production/Remaining; Production Good/NG; Material Loss & Variance; Pending QC/Hold; FG Available/Reserved; Daily FG Receipt; Daily Delivery & Outstanding; Shortage/Delivery Risk. Filter by tenant scope, plant, warehouse, customer, part, lot, WO, date, shift; drill to source. Cross-module **read** privileges do not confer posting rights.

## MVP implementation tiers
**Core vertical slice mandatory:** Core, master data, inventory, customer PO, basic WO, RM issue/consumption, production, QC, FG, shipment, variance, traceability, dashboard, audit. **V1 limited additions:** forecast/kanban, basic capacity, basic PM, stock count, import/export, PWA/notifications. **V2:** advanced scheduling, complex engineering changes, supplier quality, CAPA, IoT. Preserve explicit scope and capacity estimates.

## Consistency
Define status and ownership matrix per document; all read models must reconcile to authoritative transactions. Avoid shared database table writes across modules without domain service. Document non-functional requirements per module.

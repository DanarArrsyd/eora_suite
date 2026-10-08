# 00 — Master Product Blueprint

## Product mission
EORA Suite is an independent, multi-tenant manufacturing operations management platform for discrete manufacturing. Value proposition: **Plan. Produce. Trace. Deliver.** It is **not** a full accounting/HR ERP or a clone of SAP/Oracle.

## V1 scope
Core; Demand; Planning; Materials; Production; Quality; Inventory; Warehouse; Delivery; Maintenance (basic asset availability/PM); Traceability; Analytics. **PPIC Inventory & Fulfillment Control Tower** is a cross-module Planning workspace, not a second inventory system. Cross-cutting: PWA, bell/push, approvals, audit, Excel import/export, PDF/print/watermark, reports, secure files.

## Pilot boundary
Pilot one plant per tenant, multi-plant-ready data model; multiple tenants isolated. Include partial WO, partial QC, material substitution, returns/remnants, partial shipments, stock count/adjustment, reconciliation, correction workflow. Avoid broad general-purpose workflow builder in V1.

## Excluded / deferred
General Ledger, cost accounting/valuation, HR/payroll, full supplier procurement, advanced APS optimizer, MES machine IoT integration, predictive AI, complex process-manufacturing formulation, LIMS. Document interfaces for later phases without building them.

## Core truths
- Material at Production remains stock until consumption, transfer, scrap or return is posted.
- Semi-Finished Material is an item category for intermediate stages; **no operational WIP module/label**.
- Production output may be Pending QC, Hold, Rework, Rejected or Released; only valid warehouse receipt makes FG warehouse stock, subject to availability/reservation status.
- Customer PO vs supplier PO must be distinct. Forecast and releases may overlap with firm demand.
- Quantities are scoped by tenant/company/plant/item/lot/location/status/ownership/UOM/time.
- Cross-module views read authoritative domain records and ledger/projections; do not maintain untraceable editable dashboard totals.

## Critical user journeys
PPIC demand/supply control; warehouse RM issue and return; production run reporting; quality inspection/disposition; FG receiving/picking; delivery; monthly stock count; material variance investigation; admin correction; mobile scan/approve; export/print and import validation.

## Acceptance gate
A representative PO → WO → RM issue → actual consumption → good/NG → QC → FG receipt → shipment → reconciliation must be auditable end-to-end, including shortage, partial, concurrency, approval, correction and 25-pcs unexplained variance scenarios.

## Open decisions
Refer to `13-DECISION-REGISTER.md` before final schema, conversion formulas, tenant plan or UX approval.

# 01 — Business Process Blueprint

## Swimlanes and responsibilities
Customer/Sales: register customer PO, releases and revisions. PPIC: net demand, shortage analysis, plan/WO, daily control tower. Warehouse Materials: receive/locate/reserve/issue/return RM. Production: accept material custody, record run, consumption, outputs, NG, setup scrap, remnant. Quality: inspect and disposition output. FG Warehouse: receive QC-released FG, locate/reserve/pick/pack. Delivery: dispatch and confirm. Supervisor/Inventory Control: investigate variance, approve adjustments, close periods. Maintenance: resource availability and breakdown.

## Main flow with system checkpoints
1. Demand: capture Customer PO → map to Sales Order; forecast and Kanban/delivery releases reconcile to firm demand (no double count); record revisions/effective dates.
2. PPIC: determine net requirements against FG, eligible inventory, reservations and dates; snapshot BOM/routing revisions; check machine/dies/shift constraints; create/release WO.
3. Materials: receive RM by coil/sheet/lot; reserve and transfer issued quantity from RM warehouse to **Material at Production** (not immediately consumed); capture custody handoff.
4. Production: start one or multiple runs per WO, assign machine/dies/shift/material lots, post actual consumption, remnant/return, process scrap and good/NG; output goes to Pending QC as applicable.
5. Quality: inspect partial quantities, accept/hold/reject/rework/scrap; do not release uninspected quantities.
6. FG Warehouse: post receipt of eligible QC-released quantities, putaway/handling unit, reserve, pick, pack; availability respects status and reservations.
7. Delivery: fulfill against correct PO/release lines, support partial shipment, proof of dispatch/delivery; update outstanding quantity.
8. Reconciliation: compare material issued/consumed/returned/remnant/scrap, theoretical vs actual output, and inventory ledger; open investigation for unexplained variance; close only after approved resolution.

## Required exception flows
- PO revision after WO release; demand cancellation; forecast/release overlap; material shortage; substitution with approval and customer restriction; partial coil issue and reusable remnant; material issued but unused; duplicate scan/post; wrong lot; machine breakdown; production run spanning shifts; partial QC; QC hold/rework; output accepted but FG receipt pending; partial shipment; returned goods; stock count during active movements; closed-period correction; stale/offline PWA draft; failed notification/export worker.

## Ownership and transitions
Demand owns PO/release; Planning owns WO; Materials owns custody documents; Production owns runs/consumption/output; Quality owns inspection/disposition; Inventory owns stock movements/balance projections; Warehouse owns putaway/pick/pack; Delivery owns shipment; Core owns RBAC/audit/approval/notifications. Cross-module changes use application services/contracts and transactional events, not direct writes into another domain's tables.

## Documentation to produce before implementation
BPMN swimlanes, exception decision trees, state machines, RACI, event list, transaction posting matrix (debit/credit-like quantity movements), and end-to-end UAT trace. Define operational day/shift cut-off and time-zone policy.

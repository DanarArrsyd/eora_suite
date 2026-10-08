# 14 — Core Acceptance Scenarios (Given/When/Then)

1. **Material issue vs consumption:** Given 80 sheets in RM Warehouse, when Warehouse issues 80 to Production, then RM Warehouse decreases 80 and Material at Production increases 80; consumption remains zero until Production posts it.
2. **Sheet variance 35:** Given 80 sheets **actually consumed** at validated 10 pcs/sheet, when 760 good and 5 NG are reported and no other accountable output exists, then provisional unexplained yield variance is 35 pcs, **not** confirmed physical loss.
3. **Sheet variance 25:** Given 79 sheets consumed at 10 pcs/sheet, 760 good, 5 NG and zero other, then provisional unexplained yield variance is 25 pcs; investigation workflow starts if tolerance exceeded.
4. **Unconsumed sheet:** Given 80 issued, 77 consumed and 3 physically remain at Production, when reconciled, then theoretical based on consumed is 770 pcs and remaining 3 sheets stay in Material stock; do not mark them lost.
5. **Coil unknown inputs:** Given 700 kg issued and pitch `0.32` without units/geometry, when PPIC requests equivalent pcs, then system says 'Not calculable — missing inputs', not 2,188 pcs or confirmed loss.
6. **Partial QC:** Given 500 reported good output, when QC accepts 300 and leaves 200 pending, then only 300 can proceed to FG receipt and 200 remain Pending QC.
7. **FG distinction:** Given 300 QC accepted but 0 FG receipt posted, then FG Warehouse stock is unchanged; receipt is required.
8. **Customer demand:** Given forecast includes customer PO volume and Kanban is a release, then net demand does not double count same customer requirement.
9. **Substitution:** Given approved MAT-B replaces MAT-A, when issuing MAT-B to WO, then only MAT-B stock decreases and genealogy/approval reason is stored.
10. **Concurrent posting:** Given two users issue same lot simultaneously, when available stock is insufficient for both, then exactly one or a valid bounded combination succeeds, no oversell/duplicate posting.
11. **Reversal:** Given posted stock adjustment, when authorized correction is approved, then compensating movement is created and original history remains.
12. **Cross-tenant:** Given Tenant A user, when requesting Tenant B stock, report, push or file URL, then access is denied and logged without data disclosure.
13. **Filter parity:** Given PPIC grid filtered by plant/date/status, when exporting XLSX/PDF, then rows and permissions match server-side query and report shows cut-off/timezone.
14. **Notification reliability:** Given QC release event and a push failure, when worker retries, then one logical persistent inbox notification exists and deep link requires current permission.
15. **Import idempotency:** Given same import file/job retried, then stock postings are not duplicated and row errors are explained.
16. **Offline:** Given offline PWA draft, when reconnecting after stock changes, then server revalidates and shows conflict instead of claiming successful posting.
17. **Period closing:** Given closed period, when ordinary user attempts backdated posting, then posting is rejected or routed to authorized controlled correction according to policy.
18. **End-to-end:** Given Customer PO, when WO, issue, consumption, output, QC, FG and shipment complete, then traceability reconstructs customer shipment → FG lot → WO/run → material lot and actor/timestamps.

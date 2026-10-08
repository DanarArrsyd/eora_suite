# 14 — Core Acceptance Scenarios (Given/When/Then)

1. **Material issue vs consumption:** Given 80 sheets in RM Warehouse, when Warehouse issues 80 to Production, then RM Warehouse decreases 80 and Material at Production increases 80; consumption remains zero until Production posts it.
2. **Sheet variance 35:** Given 80 sheets **actually consumed** at validated 10 pcs/sheet, when 760 good and 5 NG are reported and no other accountable output exists, then Gross Yield Variance is 35 pcs (4.38%), provisional until finality conditions are met, **not** confirmed physical loss.
3. **Sheet variance 25:** Given 80 sheets issued, 1 sheet counted remaining and returned, and 79 sheets consumed at 10 pcs/sheet, 760 good, 5 NG and zero other, then Gross Yield Variance is 25 pcs (3.16%), provisional until finality conditions (ADR-0005) are met; investigation workflow starts if tolerance exceeded.
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
19. **Single deduction:** Given 612.400 kg consumed (weighed remaining 87.600 kg) and outputs Good 1,620 pcs, NG Reworkable 12, NG Rejected 9, scrap 128.900 kg, when the Production Posting is posted, then Material at Production decreases by exactly 612.400 kg once, outputs are posted to their own locations/statuses, and no output leg changes the consumed quantity.
20. **NG categories:** Given NG Reworkable 12 and NG Rejected 9, then they appear as `REWORK` and `REJECTED` in NG area, are excluded from FG availability, and QC may reclassify between them by status movement with reason.
21. **Scrap measured:** Given scrap posting without weight, then UMV status is not FINAL and the run cannot close without weighed scrap or confirmed zero scrap.
22. **Remnant split:** Given coil C-07 has 87.600 kg remaining and 40.000 kg is cut as reusable remnant, when lot split is posted, then child lot C-07-R1 holds 40.000 kg, parent holds 47.600 kg, the work center total is unchanged and no consumption is recorded.
23. **Three indicators:** Given the coil sample with CP validated, `SY = 0.97`, `NW = 0.290 kg/pc`, when the run closes with all measurements, then GYV = 47 pcs, PvS = −17 pcs, UMV = 7.610 kg, all FINAL, none labelled loss.
24. **Unmeasured consumption:** Given consumption posted by difference, then the posting is PROVISIONAL, an `UNMEASURED_CONSUMPTION` case opens, and GYV/UMV cannot become FINAL.
25. **Reservation:** Given 500 pcs FG unrestricted and 300 allocated to a delivery line, then on-hand stays 500 and available shows 200.
26. **Negative stock:** Given 10 kg at a work center, when consumption of 12 kg is posted, then posting is rejected and no leg is written.
27. **Pooling isolation:** Given interleaved requests for Tenant A and B over a transaction-mode pooler, then no request reads or writes the other tenant's rows, and a connection without tenant context returns no rows.

# 14 — Core Acceptance Scenarios (Given/When/Then)

1. **Material issue vs consumption:** Given 80 sheets in RM Warehouse, when Warehouse issues 80 to Production, then RM Warehouse decreases 80 and Material at Production increases 80; consumption remains zero until Production posts it.
2. **Sheet variance 35:** Given 80 sheets **actually consumed** at validated 10 pcs/sheet, when 760 good and 5 NG are reported and no other accountable output exists, then Gross Yield Variance is 35 pcs (4.38%), provisional until finality conditions are met, **not** confirmed physical loss.
3. **Sheet variance 25:** Given 80 sheets issued, 1 sheet counted remaining and returned, and 79 sheets consumed at 10 pcs/sheet, 760 good, 5 NG and zero other, then Gross Yield Variance is 25 pcs (3.16%), provisional until finality conditions (ADR-0005) are met; investigation workflow starts if tolerance exceeded.
4. **Unconsumed sheet:** Given 80 issued, 77 consumed and 3 physically remain at Production, when reconciled, then theoretical based on consumed is 770 pcs and remaining 3 sheets stay in Material stock; do not mark them lost.
5. **Coil unknown inputs:** Given 700 kg issued and pitch `0.32` without units/geometry, when PPIC requests equivalent pcs, then system says 'Not calculable — missing inputs', not 2,188 pcs or confirmed loss.
6. **Partial QC:** Given 500 reported good output, when QC accepts 300 and leaves 200 pending, then only 300 can proceed to FG receipt and 200 remain Pending QC.
7. **FG distinction:** Given 300 QC accepted but 0 FG receipt posted, then FG Warehouse stock is unchanged; receipt is required.
8. **Customer demand:** Given forecast includes customer PO volume and Kanban is a release, then net demand does not double count same customer requirement (worked cases #28–#39).
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

## Demand identity and double-counting guard (D-034, BR-DEM-05..12)
Sample data for #28–#39 (illustrative, not tenant data): customer C-01, item PART-A (pcs), weekly buckets W41–W44 (bucket size still O-011). Customer PO **CPO-1001 line 10** = 1,000 pcs mapped to **SO-2001 line 1**, scheduled as four Delivery Schedule Lines DSL-1..4 of 250 pcs (W41..W44, revision 1, Firm). Forecast FC-PART-A: W41 300, W42 1,200, W43 0, W44 0.

| Bucket | Forecast | Firm required | Delivered | Firm outstanding | Net forecast | Outstanding demand for planning |
|---|---|---|---|---|---|---|
| W41 (after #32) | 300 | 250 | 100 | 150 | 50 | 200 |
| W42 | 1,200 | 250 | 0 | 250 | 950 | 1,200 |
| W43 | 0 | 250 | 0 | 250 | 0 | 250 |
| W44 | 0 | 250 | 0 | 250 | 0 | 250 |
Never: 2,000 (PO line + SO line), 2,000 (SO line + its schedule lines), or forecast added on top of firm demand inside the same bucket.

28. **Single identity:** Given CPO-1001/10 mapped to SO-2001/1 with DSL-1..4, when firm demand for PART-A W41–W44 is computed, then it is 1,000 pcs (250 per bucket); a second mapping of CPO-1001/10 to another SO line is rejected (unique mapping).
29. **Schedule line completeness:** Given a schedule line without requirement date, without source line reference, or with UOM ≠ SO line UOM, when it is saved as Firm, then it is rejected with field-level errors and no demand changes.
30. **Forecast consumption:** Given forecast W42 = 1,200 and firm required W42 = 250, then net forecast W42 = 950 and outstanding demand = 1,200; when firm required W42 rises to 1,500, then net forecast = 0 and outstanding demand = 1,500; W41 and W43 are unchanged.
31. **Kanban release is a call-off:** Given blanket SO-2002/1 for 10,000 pcs with kanban release KB-77 = 500 pcs due W42 (Firm) and forecast W42 = 600 for that item only, then firm outstanding W42 = 500, net forecast = 100, outstanding demand = 600; unscheduled 9,500 pcs is shown separately and is not demand in any bucket (not 10,500 or 1,100).
32. **Partial fulfilment:** Given DSL-1 = 250 pcs, when shipment SHP-1 posts 100 pcs referencing DSL-1, then delivered = 100, outstanding = 150, status Partially fulfilled; a drafted (unposted) delivery order for 150 does not change outstanding.
33. **Allocation ≠ fulfilment:** Given 150 pcs FG allocated to DSL-1, then DSL-1 outstanding stays 150, firm demand is unchanged, and FG available decreases by 150 (#25).
34. **Delivery does not reopen forecast:** Given forecast W41 = 300, DSL-1 required 250 and delivered 250 (Fulfilled), then net forecast W41 = 50 and outstanding demand W41 = 50 (not 300).
35. **Shipment reversal:** Given SHP-1 (100 pcs) reversed with approval, then DSL-1 delivered = 0, outstanding = 250, status Firm; history keeps SHP-1 and its reversal.
36. **Revision:** Given CPO-1001 revision 2 changes W43 from 250 to 300 and cancels W44, when the revision is posted, then DSL-3/DSL-4 become Superseded, DSL-3′ (rev 2) = 300 is Firm, W43 demand = 300 (not 550) and W44 demand = 0; a WO pegged to DSL-4 raises a PPIC notification and is not changed. If revision 2 sets DSL-1 (delivered 100) to 80, then DSL-1′ delivered = 100, outstanding = 0 and over-delivery 20 is flagged.
37. **Idempotent schedule import:** Given the same schedule file (same source document, line references and revision) imported twice, then no new schedule lines are created and firm demand is unchanged (#15).
38. **Requirement records are derived:** Given MPS version 3 computes weekly requirement records pegged to DSL-1..4 and FC-PART-A, when firm demand is queried after the MPS run, then it is still 1,000 pcs; re-running version 3 produces the same records (unique per version/item/bucket/pegged line).
39. **MPS revision:** Given MPS version 3 approved and version 4 approved later, then version 3 becomes Superseded and its requirement records are never summed with version 4.

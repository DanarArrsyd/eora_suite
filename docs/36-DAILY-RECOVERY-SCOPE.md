# 36 — Daily Recovery Planner

Owner approved implementation on 2026-11-18 after reviewing sheets `04 11` and `05 11` in the master workbook and the table discussed in chat. This is the scope for the daily planning vertical slice.

## Decisions and gaps

- DECIDED — Production Good goes to Before Check/Pending QC according to the item inspection rule. Final QC and FG receipt retain their separate ledger transitions. Planner reads the live authoritative balances.
- DECIDED — Before Check is provisional supply for production planning, so an existing output does not trigger a duplicate SPK. It remains unavailable for delivery promises. QC NG reduces the projected stock and exposes a replacement shortage.
- DECIDED — Planning uses firm outstanding delivery, remaining WO output, configurable buffer, a 500 PCS default minimum, whole material batches, and editable Shift I–III quantities with an adjustment reason. Persist the calculation version DAILY_RECOVERY_V1 and the source fingerprint.
- DECIDED — Use the approved EORA table/detail design with the columns reviewed in chat and the revised Excel. Reuse the production shell, filters, table and forms. The owner explicitly requested this implementation.
- GAP — Excel opening balances and actuals are manually entered. Web derives them from delivery, inventory, production and quality records. Excel rows are reference examples, not operational seed data.
- GAP — An Excel row can use a manually converted material capacity. Web estimates it from approved BOM quantities and actual component stock. Missing BOM/conversion is displayed explicitly. Shared component stock is considered once in an ordered planning pass.
- GAP — Existing material ownership is OWN-only. Ownership/customer asset enforcement is the separate scope reviewed in doc 35. This slice uses the current ledger and makes no new customer-owner attribution.
- IMPLEMENTATION CHOICE — Daily Planner shows live positions, the plant's current production date and a selected target date from today onward. It groups requirements by customer/item and includes previous outstanding schedules once. Posted shipment accounting already prevents overdelivery. Saved daily parameters retain audit and source snapshots.
- IMPLEMENTATION CHOICE — Finished stock shared across customers is allocated once in date/customer/item order before search, customer filtering and pagination. Explicit delivery allocations/staging remain protected. Detail names both pooled stock and the share used for this row.
- IMPLEMENTATION CHOICE — A new SPK is a DRAFT through the existing Work Order creator and maker/checker lifecycle, with OTHER and the daily recovery reference/reason. A plan links at most one WO. After linking, an additional plan creates a new revision; the original link and parameters remain immutable. Current input fingerprint and plan version are required. Draft WOs created by this planner become projected supply, preventing a second creation for the same shortage.

## Formula and workflow

Net = max(0, outstanding firm demand + buffer − eligible FG − Before Check − QC Accepted awaiting FG receipt − remaining timely WO output). Count every supply unit once. Posted production has already left remaining WO output. Batch proposal = 0 for zero net, otherwise ceil(max(net, minimum)/batch) × batch. Missing batch leaves the proposal unavailable. Shift quantities may differ from the proposal with a recorded reason.

Pending QC, QC Accepted, unrestricted FG, staged stock, Hold, Rework and Reject stay distinct. MPS projections also include Before Check after this decision, with immutable older MPS snapshots preserved. Material readiness is an estimate and is shown separately from output stock and the planning proposal.

## Acceptance

- One output transitioning Pending QC → QC Accepted → FG does not increase combined projected stock.
- 100 FG + 400 Before Check covers a demand of 500 provisionally. QC rejection of 20 creates a net replacement shortage of 20.
- Remaining WO excludes already posted Good. Saved SPK creation is retriable and concurrency-controlled.
- Minimum 500 and 22 PCS per material batch proposes 506. Manual shift changes persist with reason and optimistic concurrency.
- Customer filters do not cause the same stock or material to cover two demands. Out-of-scope allocations remain protected.
- RLS and application checks enforce tenant and plant scope. Planning edits do not change physical stock. Backend integration tests exercise real ledger transitions and direct SQL guards. Browser checks cover the normal entry point, forms, errors, accessibility and mobile.

Skills found and read: `caveman` (`/Users/ekadanararrasyid/.codex/plugins/cache/caveman/caveman/4.1.0/skills/caveman/SKILL.md`) and `frontend-design` (`/Users/ekadanararrasyid/.codex/plugins/cache/claude-plugins-official/frontend-design/1.1.1/skills/frontend-design/SKILL.md`). The approved EORA design constitution/component standards were reviewed and applied. Inter and Modern Corporate Blue remain the owner-approved design choices.

## Release and rollback

This is a local implementation, pending the owner's release/UAT process. The application environment requires the branch's migrations through `2026_11_18_004900`, followed by the standard tenant-template permission sync so existing PPC/CSA/AUD roles receive their new template rights. Do not import Excel example actuals as operational stock. Verify approved effective BOM/UOM data and the customer's actual delivery/production/QC records through M5 UAT.

The schema round trip is tested as `eora_owner` on an empty isolated database. Once new MPS snapshots contain PENDING_QC, downgrading to the previous supply-kind constraint is deliberately refused by the database: retained history must not be deleted or reclassified just to force rollback. A release rollback with such data needs a compatible forward migration/application plan.

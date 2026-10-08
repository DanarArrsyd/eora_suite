# 13 — Decision Register and Open Questions

## DECIDED (from project discussion)
- D-001: EORA Suite is independent commercial product, multi-tenant SaaS, target **discrete manufacturing** V1.
- D-002: **No WIP operational module/menu/label**; use Material at Production, Remaining Material and Semi-Finished Material where needed; still trace intermediate physical quantities.
- D-003: Single authoritative inventory ledger; no independent stock totals by module.
- D-004: PPIC Inventory & Fulfillment Control Tower; coil/sheet estimated pcs, QC pending, FG daily receipt, daily delivery and material variance.
- D-005: Excel import/export, PDF/print and EORA document branding; desktop + PWA, persistent bell and optional push.
- D-006: Shared design system and consistent schema-driven filters; role-aware UX.
- D-007: Sensitive posted transactions reversed/adjusted with audit and approval; no silent deletion.
- D-008: Before implementation, audit specifications, create three mockup directions and request explicit approval.

## PROPOSED — require technical/owner approval
- P-001: Next.js/TypeScript + Laravel modular monolith + PostgreSQL + Redis + Reverb + S3, Docker/CI.
- P-002: Pilot one plant per tenant with multi-plant-ready model.
- P-003: Material issue as location/custody transfer, consumption as separate ledger posting.
- P-004: Draft, Pending QC, QC release, FG receipt and shipment state machine boundaries.
- P-005: Performance targets and worker/cache topology in technical spec.

## OPEN — must not silently assume
- O-001: Actual coil/sheet conversion data: units of pitch, strip layout, thickness, width, density, cavities, setup/tail losses, weighbridge precision and measurement source.
- O-002: Material scrap accounting: consumed vs separate scrap movement semantics, salvage/recycle treatment, acceptable tolerance by process.
- O-003: Intermediate stage tracking and semi-finished material part numbering for stamping→welding→assembly.
- O-004: QC stock physical location and who has custody of Pending QC/Rejected items.
- O-005: Multiple plants, subcontracting, customer-owned material/consignment, returns and external process V1 policy.
- O-006: Material stock count freeze/snapshot and posting cut-off; daily shift boundaries.
- O-007: Role-permission matrix and approval thresholds; multi-tenant vendor support access.
- O-008: Hosting/provider, region, security requirements, SLO/RPO/RTO, expected tenants/users/transaction volume.
- O-009: Pilot data migration, barcode/QR label standard, device/browser requirements, offline draft scope.
- O-010: UX direction selection, logo/design tokens, PDF watermark/print attribution policy.

## Change control
For every changed decision: record date, owner, rationale, affected modules, migrations, API/UI changes, acceptance tests, status and approval. Do not overwrite prior rationale silently.

# 37 — Material ownership foundation

Scope: first ownership slice, owner approved 2026-11-18.
- Preserve one material owner per production posting and its output/scrap lots; no mixed-owner or cross-customer exceptions.
- Company, supplier and production customer remain separate identities. Tenant/company master codes supply lot prefixes.
- Customer-owned material requires lot tracking. Existing OWN history and old lot numbers are unchanged.
- Receive, split, issue, return, consume, QC, FG receipt, allocation, shipment and reversal preserve ownership, with database bypass checks.
- New WO productionCustomerId supports unpegged customer production. A pegged delivery remains authoritative and must agree.
- Monthly PPIC monitoring, trial-NG provenance and full owner-aware automatic planning are subsequent slices, not silently inferred here.
- Existing aggregate planning cannot safely net restricted customer assets. Until owner-aware pegging is implemented, calculating a new aggregate MPS/MRP/Daily Recovery in a plant with customer-owned physical balances is refused with an actionable explanation; saved MPS/MRP snapshots and linked WOs remain readable. This is a temporary integration gate, not a material availability figure.

## Flow and source of truth

Receipt records the owner on its lot. Ledger ownership is derived from that immutable identity. Splits inherit the parent. An issue validates the WO production customer. A production posting takes one owner from its consumed lots and preserves it on every output, including NG and scrap. QC, receipt, delivery and reversal keep those lots. No dashboard owns a separate stock total.

The existing Tabel PPIC direction remains selected for the later monitoring slice. This slice extends the existing receipt, stock and WO forms, preserving shared components and the approved blue/Inter shell.

## Acceptance and release

Prove customer isolation, single-owner transformations, historical replay, concurrent numbering, lot splits, return, QC/FG, delivery and reversal. Test direct SQL as the runtime role, scoped API access and an isolated migration round trip. Never apply to the user's operational database merely to test. Before enabling customer receipt in a plant, the temporary planning gate must be understood; owner-aware planning remains follow-up work.

**Release condition (owner instruction 2026-11-19, "gas sesuai rekomendasi"):** the code merges with the plant-wide gate unchanged (`409 OWNER_PEGGING_REQUIRED`, review F-1). Do **not** apply migration `2026_11_18_005000` to the operational database, and do not receive customer material in production, until owner-aware MPS/MRP/Daily Recovery planning (next milestone scope) is approved and delivered; the first customer lot would otherwise stop planning for every customer at that plant. Narrowing the gate per item is a business-rule change and belongs to that scope.

**Lot number date:** `RM-{ownerCode}-{DDMMYY}-{sequence}` / `FG-{customerCode}-{DDMMYY}-{sequence}` stays as reviewed in doc 35 (matches the factory label style). Review item L-9 is closed without change; a different format needs a new decision because issued numbers never change.

## Open/deferred

Mixed-owner transformations and cross-customer exceptions are deferred by the owner. No exception permission or ownership-transfer action exists. Historical OWN rows are not reclassified into customer assets. Company code is read from the configured company master, never hardcoded as KMI.

Customer inactivation blocks a new customer-owned receipt or new WO customer attribution. Existing material keeps its owner through splitting, production, return and QC, including completion of an already authorized WO. An inactive master never turns existing customer assets into company stock.

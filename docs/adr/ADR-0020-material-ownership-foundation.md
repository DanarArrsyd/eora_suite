# ADR-0020 — Material ownership foundation

Date: 2026-11-18. Status: owner-approved separation policy (D-135); implementation pending review/UAT. Scope: doc 37.

## Decision

A material lot has one immutable customer or company owner. `owner_customer_id` and `owner_company_id` are composite tenant foreign keys; at most one is set. Existing OWN lots retain their original identity and numbers, with no speculative company/customer backfill. New company receipts resolve the company from the receiving plant. Stock for non-lot-controlled company items keeps the existing plant/company boundary. Customer assets require lots.

The authoritative ledger carries OWN or CUSTOMER as derived from the lot. The owner customer is identified by the immutable lot already included in the stock balance key; a second mutable customer balance is unnecessary. Database triggers require matching ownership on physical balances and every physical/virtual movement. Splits inherit the owner. Production consumes one owner and preserves it on every output, including scrap and NG. Non-lot-controlled scrap is unavailable for customer material until a traceable scrap item is configured.

`work_orders.production_customer_id` permits explicit customer attribution without a schedule line. When pegged, it must match the delivery customer. It cannot change after submission. Customer-owned material can only issue to a WO with the same customer. Allocation and delivery have the corresponding database checks. Reversals keep the original lot and owner while allowing the established corrective lifecycle.

Daily lot counters are tenant scoped and keyed by displayed namespace (RM/FG plus master code) and date, shared across items. Upserts serialize concurrent numbering inside the posting transaction. Idempotency claims precede master validation and numbering. Existing request hash shapes remain valid. Finished output uses customer/day numbering only when customer attribution exists; unassigned output and intermediate/scrap numbers retain the established format.

## Consequences

Mixed-owner transformations and cross-customer exceptions have no API or override permission. The first slice does not assign Good or NG to owners by ratios. It does not change material conversion or reconciliation formulas.

Existing aggregate planning has no customer-owner demand pegging. Temporarily, new MPS/MRP/Daily Recovery calculations in a plant with customer-owned physical balances refuse with OWNER_PEGGING_REQUIRED. Saved MPS/MRP snapshots and linked WOs remain readable; customer-specific manual WOs remain available. Owner-aware automatic planning is a subsequent slice and the integration boundary is explicit in UAT before operational release.

Rollback refuses customer-asset history rather than relabelling it OWN. DDL checks scan all rows independently of forced-RLS query visibility. Use a compatible forward rollback after customer assets exist. Deployment requires the new migration and recycling the affected pooled prepared plans; verification must not migrate the user's operational database.

## Alternatives

A report-only owner label was rejected because it cannot prevent customer asset misuse. Mixed-owner output allocation was deferred by the owner. Inferring an owner from material specification, supplier or matching RM/FG suffixes was rejected because all three can be shared or unrelated.

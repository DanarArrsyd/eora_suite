# ADR-0014 — Inventory posting engine implementation

- **Status:** Accepted as baseline (D-045); implemented M3 step 1–2 (`app/Modules/Inventory/Application/InventoryPostingService.php`, ledger guarantees in migration `002200`; idempotency in `idempotency_records`; retry = `TenantTransaction` attempts, 3 for write requests); MAT_TRANSFER D-048; numbering D-049 (no gapless promise; drafts use UUID/draft reference, official number at posting) · **Date:** 2026-10-09

## Context
ADR-0002 fixes the ledger semantics. Phase 3.5 needs an implementable engine: one service, deterministic locking, idempotency, numbering, outbox and audit in one transaction.

## Decision (proposed)
1. `InventoryPostingService::post(PostingCommand)` is the only writer of `inventory_documents`, `inventory_movements`, `stock_balances`, `stock_allocations`, `genealogy_edges`; DB grants enforce it (application role without UPDATE/DELETE on ledger tables).
2. Transaction steps and the 13-type posting matrix: doc 04 §Inventory ledger posting specification.
3. Locking: balance rows locked `FOR UPDATE` sorted by balance key; allocation checks under the same locks; `SET LOCAL lock_timeout = '3s'`, `statement_timeout = '10s'`; bounded retry (3) on `40001`/`40P01` with jitter, safe because idempotent.
4. Idempotency: `idempotency_records (tenant_id, key, request_hash, response)`; replay returns the stored response; hash mismatch → 409.
5. Document numbering: counter row per (tenant, plant, doc_type, year) locked in the same transaction (O-032); numbers never reused, reversal gets its own number.
6. New document type `MAT_TRANSFER` (O-031) for same-status location transfers.
7. Outbox + audit rows written in the same transaction; events carry ids, not quantities for decisions.
8. Every movement leg carries a `movement_code` from the system catalogue (D-084, doc 04 §Movement codes); the service picks it from the posting matrix, never from client input. A reversal leg repeats the original code with the opposite quantity and `reverses_movement_id`.

## Consequences
+ Correctness concentrated in one tested service; concurrency behaviour predictable.
− Hot lots serialize (accepted; W-07).

## Verification
Posting-matrix integration tests (doc 10), concurrency tests (doc 20 §8), projection rebuild equality, grant test (UPDATE on movements fails), every leg's code matches its matrix row and a reversal nets to zero per code.

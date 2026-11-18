# ADR-0019 — Daily Recovery and provisional QC coverage

Status: Accepted for the owner-approved 2026-11-18 Daily Recovery slice (D-134).

## Context
The revised Excel separates produced output waiting for inspection, accepted output awaiting warehouse receipt and shippable FG. Planning from FG alone duplicates production while output waits in Quality.

## Decision
Use the authoritative inventory ledger and DAILY_RECOVERY_V1 (doc 36 / BR-PLN-REC-01..03). Pending QC is separately visible provisional production coverage, superseding ADR-0015's earlier exclusion for newly calculated plans. It grants no shipping eligibility. Pool supply once across customer/item demand before filtering. Preserve allocations/staging and subtract already posted Good from remaining WO output.

Persist audited batch/minimum/buffer and editable shift plans with a source fingerprint. Reuse Draft Work Orders, permissions and maker/checker release. A linked plan is immutable; subsequent same-date plans create a new revision. A stale calculation cannot create a WO and a repeated creation returns its existing WO.

## Consequences
QC rejection exposes replacement need; moving accepted goods into FG does not increase combined coverage. New MPS calculations include distinct PENDING_QC supply; previous snapshots are unchanged. Capacity remains a master-data-dependent estimate. Customer-asset ownership enforcement remains doc 35's separate scope. No physical stock posting is introduced by daily planning.

## Verification
DailyRecoveryTest exercises real posting/QC/FG receipt, shared customer allocation, source changes, optimistic concurrency, authorization and immutable history. RecoveryCalculationTest covers batch rounding; browser tests cover normal entry, editable shifts, linked WO, errors, mobile and accessibility.

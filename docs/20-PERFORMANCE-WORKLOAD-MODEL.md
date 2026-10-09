# 20 — Performance Baseline and Workload Model

**Decision:** D-019 (AG-11). Baseline **test assumption**, not a product limit: 20 tenants × 150 users, 30% concurrency, 5,000 postings/day/tenant. All derived numbers below are modelling assumptions to be replaced by pilot telemetry.

## 1. Derived baseline
| Quantity | Derivation | Value |
|---|---|---|
| Named users | 20 × 150 | 3,000 |
| Concurrent sessions | 3,000 × 30% | 900 |
| Postings/day (all tenants) | 5,000 × 20 | 100,000 |
| Average posting rate | 100,000 / 86,400 s | ~1.2/s |
| Peak hour share | assumption: 20% of daily postings in the hour around shift change | 20,000/h ≈ 5.6/s |
| Burst factor within peak | ×3 (shift-end reporting spike, 5-min windows) | ~17 postings/s |
| Ledger legs | avg 4 legs per posting (issue 2, production posting 4–6, QC 2, shipment 2) | ~400k legs/day; ~146M/year |

"Posting" = one inventory document committed (issue, return, split, production posting, QC disposition, FG receipt, pick, shipment, adjustment).

## 2. Peak-shift transaction mix (writes)
| Txn | Share of postings | Notes |
|---|---|---|
| Production Posting (per run/shift) | 30% | heaviest: 4–6 legs + genealogy + variance recalculation event |
| QC disposition | 20% | partial lots |
| Material issue / custody accept | 15% | hot-lot contention |
| FG receipt + pick | 15% | |
| Shipment | 8% | |
| Material return / lot split | 7% | measured |
| Adjustment / reversal / count | 5% | approval path |

## 3. Read workload (per concurrent session class)
| Class | Share of 900 | Pattern | Approx. requests/s |
|---|---|---|---|
| Floor PWA (operator, QC, FG) | 55% (~495) | task list poll 30 s + scan lookups | ~25 |
| Desktop operational (materials, delivery, quality supervisor) | 30% (~270) | grid filter/sort/paginate every 20 s | ~14 |
| PPIC / Control Tower | 10% (~90) | overview refresh 60 s + drill-downs | ~5 (overview) + ~3 (drill) |
| Admin/audit/reports | 5% (~45) | ad-hoc | ~2 |
| Bell badge (realtime; fallback poll 60 s) | 900 | websocket; poll only on fallback | ≤15 |
Planning figure: **~60–80 read requests/s sustained, ~200/s burst**, read:write ≈ 10:1.

## 4. Async workloads
| Workload | Model |
|---|---|
| Import | per tenant, peak 2 concurrent jobs × 5,000 rows; chunked commit 500 rows; must not raise posting p95 > 20% |
| Export | 10 concurrent XLSX exports of 50k rows; 5 PDF reports 50 pages; queued on separate worker pool |
| Notification fan-out | ~1.5 events per posting × avg 5 recipients → ~7.5 inbox rows per posting → ~130 inbox inserts/s at burst; push optional, rate-limited |
| Outbox relay | lag target p95 < 5 s at burst |
| Read-model consumers | lag target p95 < 2 min (doc 19) |
| Nightly jobs | projection vs ledger reconciliation, read-model verification, per tenant, outside peak |

## 5. Scenarios (k6 + DB-level concurrency tests)
| ID | Scenario | Pass criteria (proposed) |
|---|---|---|
| W-01 | Baseline mixed load 900 VU, 60 min | read p95 < 300 ms; posting p95 < 800 ms; error rate < 0.1% |
| W-02 | Peak shift burst 17 postings/s, 15 min | posting p95 < 1.5 s; zero duplicate postings; zero negative balances |
| W-03 | Dashboard storm: 90 PPIC users open Overview within 60 s | Overview p95 < 1.0 s |
| W-04 | Import 2×5,000 rows during W-02 | posting p95 degradation ≤ 20%; import idempotent on retry |
| W-05 | Export 10×50k rows during W-01 | read p95 degradation ≤ 20% |
| W-06 | Notification fan-out burst | outbox lag p95 < 5 s; one inbox item per recipient per event |
| W-07 | Hot lot: 10 concurrent issues/consumptions on same lot | serialized correctly; no deadlock errors surfaced; p95 < 2 s |
| W-08 | Tenant isolation under load (interleaved tenants, pooler) | zero cross-tenant rows (ADR-0001 T-ISO-03) |
| W-09 | Genealogy backward trace on 1-year data | p95 < 2 s |
| W-10 | Soak 8 h at 50% baseline | no memory growth in workers; queue depth stable |
| W-11 | Scale step 2× and 5× users (if justified) | identify first bottleneck; no correctness failures |

## 6. Test environment and data
- Environment sized and documented before running (CPU/RAM, DB instance, pooler, worker count); results valid only for that topology.
- Synthetic data generator per tenant: 1 plant, 6 work centers, 400 items, 2,000 RM lots, 12 months of movements at baseline rate; generator output clearly marked synthetic.
- Results recorded with commit SHA, config and date; SLOs accepted only after review (P-005).

## 7. Capacity levers (apply only if measurements require)
Indexes with `tenant_id` leading; monthly partitioning of `inventory_movements`; read replicas for Control Tower read models; Octane for API; separate worker pools (postings-adjacent, notifications, exports); closure table for genealogy.

## 8. Concurrency risk register (Phase 3.5)
| Risk | Where | Mitigation | Test |
|---|---|---|---|
| Double issue / oversell of one lot | issue, consumption, allocation, shipment | `FOR UPDATE` on balance rows in key order; `CHECK (qty >= 0)`; allocation check under the same lock | doc 14 #10, W-07 |
| Deadlock between multi-lot postings | production posting with several input lots, transfers | deterministic lock order (balance key sort), single transaction, bounded retry on `40P01` | W-02, deadlock injection test |
| Duplicate posting on retry / double click | all commands | `Idempotency-Key` unique per tenant + stored response | #15, replay test |
| Lost update on drafts | documents, MPS lines | `version` + `If-Match` → 412 | optimistic-lock test |
| Projection drift | `stock_balances` vs ledger | same-transaction update + nightly verification | BR-INV-11 rebuild test |
| Outbox double delivery | notifications, read models | idempotent consumers (`processed_events`) | #14, W-06 |
| Document number gaps/duplicates | numbering | counter row locked inside the posting transaction (O-032) | concurrency numbering test |
| Long transactions holding locks | imports, recalculation | chunking, `statement_timeout`/`lock_timeout`, no external I/O in transactions | W-04 |
| Tenant context leak via pooler | all | transaction-local context, fail-closed RLS | T-ISO-03, W-08 |
| Read-model stampede on dashboard | CT-0 | Redis cache per scope hash + single-flight recompute | W-03 |


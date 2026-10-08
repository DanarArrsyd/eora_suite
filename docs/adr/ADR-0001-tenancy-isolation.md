# ADR-0001 — Multi-tenancy and tenant isolation

- **Status:** Accepted (D-009, DD-07) · **Date:** 2026-10-08

## Context
EORA is multi-tenant SaaS holding manufacturing stock, customer demand and quality data. A leak between tenants is a critical incident. Options considered: (a) shared DB + `tenant_id`, (b) schema per tenant, (c) database per tenant. Expected scale (D-019): tens of tenants initially, growth expected; one ledger design must serve all.

## Decision
1. **Shared PostgreSQL database**, single schema per bounded context namespace (table prefixes or PostgreSQL schemas per module — chosen at Foundation, not per tenant).
2. Every tenant-owned table has `tenant_id uuid NOT NULL`. Primary key `id` (UUIDv7/ULID) plus `UNIQUE (tenant_id, id)`. **All foreign keys between tenant-owned tables are composite** `(tenant_id, x_id) REFERENCES x (tenant_id, id)`, so a row can never point to another tenant's row even if application code is wrong.
3. Unique business keys are tenant-scoped, e.g. `UNIQUE (tenant_id, doc_type, doc_no)`.
4. **Layer 1 — application:** tenant resolved from authenticated session membership (DD-07), never from a client header or URL. Eloquent global scope adds `tenant_id`; repository/query classes require a `TenantContext` value object; authorization policies check tenant + plant/warehouse scope (doc 05).
5. **Layer 2 — RLS (defense-in-depth):** `ALTER TABLE … ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY` on tenant tables; policy `USING (tenant_id = current_setting('app.tenant_id')::uuid) WITH CHECK (same)`. The application DB role is **not** owner and has no `BYPASSRLS`. Migrations run under a separate owner role.
6. **Tenant context propagation rule (pooling-safe):** tenant context is set with `SELECT set_config('app.tenant_id', $1, true)` — i.e. `SET LOCAL` semantics — **inside a transaction**. All tenant DB work (reads included) executes inside a transaction opened by a `TenantTransaction` middleware/unit-of-work. If `app.tenant_id` is unset, RLS policies match nothing (fail closed: `current_setting('app.tenant_id', true)` returns NULL → no rows). This remains correct under PgBouncer transaction pooling and under persistent PHP workers.
7. **Workers/queues:** every job payload carries `tenant_id` and the initiating `user_id` (or `system` actor). A job middleware establishes TenantContext and opens the tenant transaction; jobs without tenant context throw before touching tenant tables. Platform-level jobs (tenant provisioning, metrics) use explicit, audited platform services that never read operational tables.
8. **Other channels:** object-storage keys prefixed `tenants/{tenant_id}/…`; cache keys prefixed `t:{tenant_id}:`, plus permission scope hash where results are permission-dependent; Reverb private channels `tenant.{tenant_id}.user.{user_id}` authorized server-side; outbox, inbox, import/export jobs and logs all carry `tenant_id`.
9. Hierarchy: `tenant` = customer account; `company` = legal entity inside tenant; `plant` → `warehouse` → `location`. V1 pilot: one company, one plant active per tenant; schema supports many.

## Consequences
+ One schema to migrate; cross-tenant platform ops simple; strong DB-level backstop.
− Every query/index needs `tenant_id` as leading column; noisy-neighbour risk handled by rate limits and later sharding by tenant if needed.
− Requests run inside transactions (including reads) — keep them short; no external I/O inside.

## Verification (mandatory automated tests)
- T-ISO-01: Tenant A user requests Tenant B record by ID via API, export, report, file URL, notification deep link, search, websocket channel → 404/403, no data, audit event.
- T-ISO-02: RLS backstop — raw query with application role and tenant A context cannot read/write tenant B rows, including via composite FK insert attempts.
- T-ISO-03: Pooling leak — interleave requests of tenants A/B over a transaction-mode pooler and persistent workers; assert zero cross reads; assert unset context returns zero rows.
- T-ISO-04: Queue job for tenant A processed after job for tenant B on the same worker process never sees B context.
- T-ISO-05: Cache/permission-scope keys differ per tenant and per scope; export file paths unguessable and tenant-prefixed.

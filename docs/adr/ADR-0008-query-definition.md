# ADR-0008 — Shared QueryDefinition for grid, export and dashboards

- **Status:** Accepted (D-006, DD-06) · **Date:** 2026-10-08

## Context
Docs 06/08 require schema-driven filters, URL-synced state and **identical** server query scope for grid and XLSX/PDF export (doc 14 #13). Without one shared mechanism, each page re-implements filters and exports drift from grids.

## Decision
1. Backend: one `QueryDefinition` class per listable resource (e.g. `MaterialLotsQuery`, `PendingQcQuery`, `DailyFgReceiptQuery`). It declares:
   - filter fields (key, type, operators, allowed values source, required permission, default),
   - sortable fields, default sort, max page size,
   - columns (key, label key, type, unit field, sensitivity class, export format),
   - row-level scope (tenant + plant/warehouse permission scope applied **inside** the class, not by callers),
   - `as_of` semantics (live vs read-model with freshness timestamp).
2. Endpoints:
   - `GET /api/v1/query/{resource}/schema` → filter/column schema filtered by caller permissions.
   - `GET /api/v1/query/{resource}?filter[...]&sort=&page[size]=&page[cursor]=` → rows + `meta {as_of, timezone, total|has_more, scope_hash}`.
   - `POST /api/v1/exports {resource, filter, sort, columns, format}` → async job that runs **the same class** with the same parameters, snapshotting `as_of`.
3. Frontend: `EoraFilterBar` renders from the schema; `EoraDataTable` columns from schema + per-page column overrides (formatting only); state serialized into URL query string; saved views stored server-side per user/role (permission re-checked on load).
4. Pagination: cursor/keyset for large ledgers and movement lists; offset allowed for small master data.
5. Sensitive columns: excluded server-side for callers without permission; never hidden only in UI.

## Consequences
+ Export parity by construction; one place to test scope and permission.
− QueryDefinition classes become a core abstraction — keep them declarative, no business mutations.

## Verification
Parity test: for each resource, grid rows (all pages) == export rows for same filter and user; permission-filtered schema; sensitive columns absent from both.

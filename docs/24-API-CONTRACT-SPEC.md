# 24 — API Contract Specification

**Status:** APPROVED as backend baseline (D-045); tooling APPROVED WITH CONDITIONS (D-047) · Decision basis: ADR-0006 (topology/auth), ADR-0008 (+ Amendment 1, QueryDefinition), ADR-0013 (API conventions, proposed). Canonical for endpoint shapes, query grammar, error model and frontend compatibility. No backend exists yet; implementation follows the slice plan in doc 11.

## 1. Principles
1. **Backend is the source of truth** for every business number (stock, outstanding, estimates, indicators, risk, KPIs). The frontend presents, formats, interacts and navigates (authorized); it never computes stock or variance.
2. One QueryDefinition per listable resource drives **grid, schema, counts and export** (parity by construction).
3. Commands (writes) are explicit, idempotent and versioned; queries are side-effect free `GET`.
4. Tenant and plant scope come from the server session, never from request parameters (DD-07).

## 2. Versioning and documentation
- Path versioning `/api/v1/…`; additive changes only within v1 (new optional fields, new resources); breaking changes → `/api/v2` with a deprecation window and `Deprecation`/`Sunset` headers.
- **OpenAPI 3.1** generated code-first from Laravel (Scramble, D-047 — versions/compatibility verified before installation) and published per build; CI fails on undocumented breaking diffs (`oasdiff`).
- Typed client generated into `packages/api-client` (proposed `openapi-typescript` + `openapi-fetch`); `ListSource<T>` adapters wrap it, so pattern components never call `fetch` directly.

**B0 implementation:** Scramble 0.13.45 exports OpenAPI 3.1 to `packages/api-client/openapi.json` (`composer openapi:export`); `@eora/api-client` generates `schema.d.ts` with openapi-typescript 7.13.0 and exposes `createApiClient()` (openapi-fetch 0.17.0, same-origin `/api/v1`, `credentials: 'include'`) plus the `ProblemDetails` type; CI fails on spec or type drift. Error codes added in B0: `METHOD_NOT_ALLOWED` (405), `INTERNAL_ERROR` (500). Problem schemas are not yet emitted into the spec (Scramble extension planned with B1); the TS type is hand-written to match §5.

## 3. Query resources (ADR-0008)
| Endpoint | Purpose |
|---|---|
| `GET /api/v1/query/{resource}/schema` | filter fields, columns, sorts, views, export formats — already filtered by permission |
| `GET /api/v1/query/{resource}` | rows + meta |
| `GET /api/v1/query/{resource}?counts=views` | view (status-tab) counts for the same filter scope, no rows |
| `GET /api/v1/query/{resource}/options/{field}?depends[plant]=PA` | options for `source: 'api'` / `entity` filter fields |
| `POST /api/v1/exports` `{resource, filter, sort, columns, view, format}` | async export job running the same QueryDefinition |
| `GET /api/v1/saved-views?resource=` · `POST` · `PATCH /{id}` · `DELETE /{id}` | saved views (personal / shared by role), permission re-checked on load |

### 3.1 Query grammar (maps 1:1 to `ListQuery`)
| ListQuery | Query string |
|---|---|
| `filters.k = 'v'` | `filter[k]=v` |
| `filters.k = ['a','b']` | `filter[k][]=a&filter[k][]=b` |
| daterange `'from..to'` | `filter[k][from]=2026-10-01&filter[k][to]=2026-10-31` |
| `search` | `q=…` (server decides searched columns) |
| `view` | `view=hold` (server-side preset; never a client-built filter) |
| `sort [{id, desc}]` | `sort=-requirementDate,id` (stable tiebreaker `id` appended by server) |
| `page`, `pageSize` | `page[number]=2&page[size]=50` (offset mode) |
| cursor mode | `page[cursor]=<opaque>&page[size]=50` |
Unknown filter keys → `422 FILTER_NOT_ALLOWED`; page size above the resource max → clamped and reported in `meta.page.size`.

### 3.2 Response envelope
```json
{
  "data": [ { "id": "…", "outstanding": "150.000000", "uom": "pcs", "...": "…" } ],
  "meta": {
    "total": 214,                       // offset mode; omitted in cursor mode
    "hasMore": true, "nextCursor": "…", // cursor mode
    "page": { "number": 2, "size": 50 },
    "asOf": "2026-10-09T01:14:00Z",
    "timezone": "Asia/Jakarta",
    "freshness": { "source": "read_model", "lagSeconds": 95, "stale": false, "thresholdSeconds": 600 },
    "scopeHash": "sha256:…"               // tenant + plant scope + permissions + filters; equals export job scope
  }
}
```
Quantities travel as **decimal strings** (`numeric(20,6)` exact); the client formats them by UOM (kg 3 dp, pcs/sheet 0). Dates: `date` as `YYYY-MM-DD` in plant calendar, instants as ISO UTC.

### 3.3 Pagination policy
Offset (`total`) for master data and small lists; **cursor/keyset** for ledgers, movements, daily production, variance history and any resource that can exceed ~10k rows (Postgres keyset on the sort columns + id). Status counts are returned only by `counts=views` (cached per scope hash, short TTL).

### 3.4 KPI read models
`GET /api/v1/query/ct.overview` returns one row: `kpis[]` (value, uom, secondary, definition, sourceDomain, basis, meaning, href), `flow` stages per UOM band, top-N `exceptions` ranked server-side, `dueVsSupply`, plus `meta.asOf/freshness`. Numbers come from rebuildable read models (doc 19 §4) carrying the ledger high-water mark; `stale=true` when lag > threshold (tenant default 10 min).

## 4. Commands (writes)
- `POST /api/v1/{module}/{resource}` (create draft) · `PATCH …/{id}` (edit draft, requires `If-Match: "<version>"`) · `POST …/{id}/actions/{submit|approve|post|reverse|release}`.
- Posting commands: `POST /api/v1/inventory/documents` `{docType, lines[], productionDate?, sourceRef}` with header `Idempotency-Key: <uuid>` (required) → `201` with document + legs, or replay of the original response; same key + different payload → `409 IDEMPOTENCY_KEY_REUSED`.
- Concurrency: stale `If-Match` → `412 VERSION_CONFLICT` with current representation; lock timeout / serialization exhaustion → `503 RETRYABLE_CONFLICT` with `Retry-After`.
- Every command returns `correlationId`; audit + outbox in the same transaction (doc 04).

## 5. Error contract (RFC 9457 `application/problem+json`)
```json
{ "type": "https://errors.eora.app/INSUFFICIENT_STOCK", "title": "Insufficient stock", "status": 409,
  "code": "INSUFFICIENT_STOCK", "detail": "Lot C-09 has 10.000 kg at WC-PRESS1; 12.000 kg requested.",
  "correlationId": "01J…", "reference": "7c1e-55a2",
  "errors": { "lines[0].qty": ["exceeds available 10.000 kg"] } }
```
| HTTP | Codes (catalogue, extensible) |
|---|---|
| 400 | `MALFORMED_REQUEST` |
| 401 | `UNAUTHENTICATED`, `SESSION_EXPIRED` |
| 403 | `FORBIDDEN`, `SCOPE_FORBIDDEN`, `SOD_VIOLATION`, `STEP_UP_REQUIRED` |
| 404 | `NOT_FOUND` (also for other tenants' ids — no existence disclosure) |
| 409 | `INSUFFICIENT_STOCK`, `INSUFFICIENT_AVAILABLE`, `PERIOD_CLOSED`, `IDEMPOTENCY_KEY_REUSED`, `INVALID_STATE_TRANSITION`, `REVERSAL_BLOCKED_BY_DOWNSTREAM`, `BLANKET_EXCEEDED`, `REVISION_MAPPING_AMBIGUOUS` |
| 412 | `VERSION_CONFLICT` |
| 422 | `VALIDATION_FAILED`, `FILTER_NOT_ALLOWED`, `UOM_MISMATCH`, `MEASUREMENT_REQUIRED` |
| 429 | `RATE_LIMITED` |
| 503 | `RETRYABLE_CONFLICT`, `READ_MODEL_UNAVAILABLE` |
The frontend maps `403 FORBIDDEN/SCOPE_FORBIDDEN` → `SourceError('forbidden')` (PermissionDeniedState), 5xx → `SourceError('server')` with `reference`; `422 errors` → form field errors (EoraForm already maps them).

### 5.1 Implemented in M1 (B1)
- `503 RETRYABLE_CONFLICT` with `Retry-After` is returned for serialization failure, deadlock and lock timeout (SQLSTATE 40001, 40P01, 55P03); nothing was committed.
- `403 SCOPE_FORBIDDEN` / `SOD_VIOLATION` are recorded as `access.scope_forbidden` / `access.sod_violation` security events (doc 25 §8).
- Administration (M1 step 5): `GET /api/v1/core/roles`, `GET /api/v1/core/members`, `POST /api/v1/core/role-assignments` (`{membershipId, roleId, scopeType, companyId?, plantId?, validUntil?, reason}` → 201), `POST /api/v1/core/role-assignments/{id}/actions/revoke` (`{reason}` → 200). Member administration (step 5b): `POST /api/v1/core/members/invitations` (`{email}` → 201), `POST /api/v1/core/members/{id}/actions/resend-invitation` (→ 202), `…/actions/suspend`, `…/actions/reactivate` (→ 200). Problem responses are not yet emitted into the OpenAPI document (step 9).

## 6. Security on every endpoint
Session + CSRF (non-GET), tenant from session, permission + scope policy, rate limits (doc 05), audit for commands and sensitive reads, response never contains other-tenant ids; export files tenant-prefixed and served through short-lived signed URLs.

## 7. Frontend compatibility audit (Phase 1–3 components)
| Component / contract | Backend contract | Compatible? | Change needed (impact) |
|---|---|---|---|
| `ListQuery` / `useListUrlState` | §3.1 grammar | Yes | adapter maps fields; none in components |
| `ListMeta {total, asOf, timezone}` | §3.2 meta | Yes (subset) | **additive**: optional `hasMore`, `nextCursor`, `freshness` in the TS type — low impact, no breaking change |
| `Pagination` | offset mode | Yes | cursor resources need a Prev/Next-only variant (standards §6 already describes it) — small additive prop `mode="cursor"`; affects CT-6/daily screens only when switched |
| `FilterBar` / `FilterField` | `/schema` + `/options/{field}` | Yes | schema → `FilterField[]` mapper in the adapter; `loadOptions` wraps `/options` |
| `StatusTabs` counts | `?counts=views` | Yes | none |
| `DataTable` / `EoraColumn` | `/schema` columns + page column defs | Yes | cell renderers stay page-side (formatting only) |
| Saved views (`SavedViewsMenu`) | `/saved-views` | Yes | replace mock store; none in component |
| `ExportMenu` / `ExportSource` | `POST /exports` | Yes | adapter sends current `ListQuery` + `scopeHash` |
| `ImportWizard` / `ImportSource` | import job endpoints (doc 08) | Yes | none |
| `NotificationCenter` / `NotificationSource` | `GET /api/v1/inbox`, `POST /inbox/read` | Yes | none |
| Control Tower `contracts.ts` | `ct.*` resources | Yes | quantities become decimal strings → parse once in the adapter (`Number` is safe for display; never for arithmetic in UI) |
| `EoraForm` `SubmitResult.fieldErrors` | §5 `errors` | Yes | adapter maps problem+json |
No shared component must change before the backend exists; the two additive type/prop extensions are scheduled with the first cursor-paged resource.

# 05 — Security, Tenancy, RBAC & Approvals

## Roles
Platform Super Admin (vendor operations only; no default unrestricted tenant transactions); Company Super Admin (tenant-wide governance); Module Admin; PPIC; Materials PIC; Production Operator/Leader; Quality Inspector/Supervisor; FG Warehouse; Delivery; Maintenance; Approver; Auditor/Viewer. Roles are examples, not hard-coded permissions.

## Permission tuple
`tenant + company + plant + warehouse/department/resource + module + entity + action + condition`. Actions include view, create draft, edit draft, submit, release, reserve, issue, consume, inspect, post, approve, reverse, adjust, close, import, export, print, download. Enforce backend and worker scope, not only hidden UI.

## Separation of duties
Posting vs approving corrections/stock adjustments; QC release vs production report; count vs approve count variance; export sensitive data per policy. Break-glass access requires approval, expiry and audit. Company admin cannot hard-delete posted stock.

## SaaS security baseline
Tenant isolation tests; session/cookie security, CSRF/XSS protections, MFA for privileged users, rate limits, secrets rotation, secure object URLs, signed downloads, malware/content scanning for uploads where appropriate, encrypted transit/at-rest, audit retention, backup and recovery. Avoid leaking data via notification lock screens, push payloads, file names or search suggestions. Explicit security review of barcode deep links and cross-tenant resource IDs.

## Approval workflow
Draft → Submitted → Reviewed → Approved/Rejected → Posted (as applicable); require reason, immutable history, assigned approver, maker-checker and expiration/escalation policy. Rejected/voided documents remain traceable.

## Phase 3.5 security architecture (2026-10-09; normative details in ADR-0001, ADR-0006, ADR-0013)
- **Topology:** same origin (ADR-0006): reverse proxy routes `/api/*`, `/sanctum/*`, `/broadcasting/auth` to Laravel, everything else to Next.js; production has **no CORS** (local dev origins only); Next.js holds no tokens and performs no mutations server-side.
- **Authentication:** Sanctum stateful SPA (session cookie `__Host-` prefix, `Secure`, `HttpOnly`, `SameSite=Lax`, no Domain); CSRF via `XSRF-TOKEN` / `X-XSRF-TOKEN` on every state-changing request; session regenerated on login, tenant switch and privilege elevation; idle + absolute lifetime per tenant policy; MFA + step-up for approvals, reversals and adjustments.
- **Authorization:** permission + scope evaluation in Laravel policies (doc 18 §5 evaluation order); plant/warehouse/work-center scope in query predicates; SoD rules in policies; frontend hiding is UX only.
- **Tenant isolation:** composite FKs + RLS (enabled, forced, fail-closed) + transaction-local tenant context + application role without ownership/BYPASSRLS; RLS covers **tenant only** — plant scope stays in the application layer (keeps policies cheap and indexable).
- **Rate limiting (proposed defaults):** login 5/min per account + 20/min per IP with progressive delay; API 300 req/min per session, 60/min for posting commands per user, export/import job creation 10/hour per user and 50/hour per tenant; `429` with `Retry-After`; limits keyed by tenant to contain noisy neighbours.
- **Audit:** every command writes `audit_events` (actor, tenant, plant, action, entity, before/after hash, correlation id, IP/user agent) in the same transaction; read access to sensitive exports audited.
- **Input validation:** FormRequest validation mirrored by Zod on the client (client validation is convenience only); quantities as decimal strings in JSON to avoid float rounding.
- **Secrets:** environment-injected from a secrets manager; no secrets in repo; key rotation runbook.

## Open
SSO/MFA rollout, access review frequency, retention jurisdiction, platform support impersonation policy, tenant-level export policy, data deletion/contract termination and subscription entitlements.

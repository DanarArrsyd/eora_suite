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

## Open
SSO/MFA rollout, access review frequency, retention jurisdiction, platform support impersonation policy, tenant-level export policy, data deletion/contract termination and subscription entitlements.

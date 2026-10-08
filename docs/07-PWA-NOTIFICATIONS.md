# 07 — Mobile PWA, Eventing & Notification Center

## PWA
> UI patterns: see `docs/uiux/EORA-PAGE-TEMPLATES.md` §5 (mobile task template, D-021).

Installable over HTTPS with manifest/service worker; role-based home, My Tasks, My Shift, Scan, Transaction Wizard, Approvals, Inbox, Profile/Device. Camera scan with permission and manual fallback. Responsive, accessible, supports degraded connectivity. Offline drafts permitted only for approved non-posted forms; critical stock/quality/delivery posting requires server confirmation and revalidation. Display pending sync/conflict state clearly.

## Reliable event pipeline
Domain transaction commits + transactional outbox → worker → recipient resolution (tenant, plant, role, assignment, permission, preferences) → durable inbox → realtime WebSocket/SSE/poll fallback → optional Web Push. At-least-once with deduplication, retries, dead-letter, idempotent consumers. Do not claim exactly-once delivery.

## Events
Customer PO changed → PPIC; WO released → Production; RM shortage → PPIC/Materials; material issued → Production; output Pending QC → Quality; QC released → FG Warehouse; variance threshold → Supervisor/PPIC; shipment delayed → Delivery; breakdown → Maintenance/PPIC; stock count adjustment approval → approver; export/import job complete → requestor.

## Bell behavior
Persistent unread/action-required badges; filters, mark read, action status, severity, timestamp, deep link to exact authorized record, quiet hours and user preferences. Permission and tenant checked again on click. No sensitive stock/customer content in lock-screen push by default. Push requires user opt-in and supported platform/browser; on compatible iOS configurations, Home Screen installation may be required. Bell inbox remains authoritative when push unsupported.

## Acceptance
No cross-tenant push; duplicate outbox processing yields one logical inbox event; revoked user loses access to deep link; failed push does not lose in-app event; offline draft never displays 'posted' before server acknowledgment.

# 12 — SaaS Product Operations

## Tenant lifecycle
Tenant registration/provisioning → company/plant configuration → admin onboarding → role/permission presets → master data import → controlled go-live → support → renewal/offboarding. Tenant ID must bind DB scope, jobs, files, exports, events, metrics and backup recovery.

## Commercial configuration
Module entitlements, plan limits, user seats and usage metering are **design considerations**, not a finalized pricing/billing system in V1. Define support tiers, SLA, incident response, maintenance windows, data export on termination, tenant data retention and regional requirements before launch.

## Configurable policies
Shift calendars, numbering, QC method, approval thresholds, variance tolerances, production cut-off, plants/warehouses, timezone, customer document labels, feature flags. Do not make immutable ledger semantics or tenant security configurable.

## Operational readiness
Runbooks for failed queue, outbox backlog, notification push errors, slow queries, failed imports, restore, stock inconsistency and urgent correction; admin dashboards without unrestricted vendor access; audit access logs and access reviews.

# Architecture Decision Records

Format: Nygard (Context / Decision / Consequences) plus Verification. Status values: `Proposed`, `Accepted`, `Superseded by ADR-xxxx`. An ADR is `Accepted` only when the linked decision in `docs/13-DECISION-REGISTER.md` is DECIDED.

| ADR | Title | Status | Decision |
|---|---|---|---|
| [0001](ADR-0001-tenancy-isolation.md) | Multi-tenancy and tenant isolation | Accepted | D-009, DD-07 |
| [0002](ADR-0002-inventory-ledger.md) | Inventory ledger, stock status, periods | Accepted | D-013..D-015, DD-01..DD-03 |
| [0003](ADR-0003-consumption-measurement.md) | Actual consumption measurement | Accepted | D-010 |
| [0004](ADR-0004-output-categories.md) | Output categories and transformation posting | Accepted | D-011, DD-04 |
| [0005](ADR-0005-variance-indicators.md) | Variance indicators and finality | Accepted | D-012 |
| [0006](ADR-0006-web-api-auth-topology.md) | Web/API topology, Sanctum, cookie/CSRF/CORS | Accepted | D-016, DD-05 |
| [0007](ADR-0007-platform-runtime.md) | Platform runtime, queues, outbox, realtime, storage | Proposed | P-001 remainder, P-005 |
| [0008](ADR-0008-query-definition.md) | Shared QueryDefinition for grid/export/dashboards | Accepted | D-006, DD-06 |
| [0009](ADR-0009-ui-direction-design-system.md) | Hybrid UI direction and design system | **Superseded by ADR-0010** | D-018 (superseded) |
| [0010](ADR-0010-modern-corporate-ui.md) | Modern Corporate Enterprise Workspace (+ Amendments 1–2) | Accepted; official baseline (D-027) | D-021, D-024..D-029 |
| [0011](ADR-0011-frontend-architecture.md) | Frontend architecture and phased implementation | Accepted | D-033 |
| [0012](ADR-0012-business-calendar-time-model.md) | Business calendar, production date and time model | Accepted | D-044, D-045 |
| [0013](ADR-0013-api-contract-conventions.md) | API contract conventions | Accepted (tooling with conditions) | D-045, D-047 |
| [0014](ADR-0014-inventory-posting-engine.md) | Inventory posting engine implementation | Accepted | D-045, D-048, D-049 |
| [0015](ADR-0015-supply-classification-and-netting.md) | Supply classification and MPS netting | Accepted | D-043, D-046 |
| [0016](ADR-0016-read-models-freshness-caching.md) | Read models, KPI freshness and caching | Accepted | D-045 |

Only ADR-0011 authorizes implementation, and only phased frontend work (D-033). ADR-0012..0016 are the approved backend baseline (D-045); backend code proceeds only slice by slice with explicit owner approval. Backend, schema, migrations and business-rule changes still require explicit owner approval.

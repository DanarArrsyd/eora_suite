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
| [0010](ADR-0010-modern-corporate-ui.md) | Modern Corporate Enterprise Workspace (+ Amendment 1) | Accepted; baseline conditionally approved, final approval pending | D-021, D-024..D-026 |

No ADR authorizes implementation. Implementation still requires the next explicit owner approval (D-008, AG gate follow-up).

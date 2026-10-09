# Modules (modular monolith, ADR-0007, doc 09)

Business modules are added slice by slice (doc 11) — none exist in B0.

```
app/Modules/<Module>/
  Contracts/        DTOs, command/query interfaces, domain events — the only cross-module surface
  Domain/           entities/value objects, state machines (doc 16), rule objects (BR-*)
  Application/      command handlers (idempotent), QueryDefinitions (ADR-0008), event consumers
  Infrastructure/   tenant-scoped repositories, projectors, external adapters
  Http/             thin controllers, FormRequests, API resources
```
Rules (enforced by `tests/Arch`): modules depend on other modules only through `Contracts`; `App\Platform`
(cross-cutting: correlation id, problem+json, health, later tenancy/idempotency/outbox) never depends on modules;
only the Inventory module writes the ledger (ADR-0014).

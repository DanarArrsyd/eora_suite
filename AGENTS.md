# EORA Suite — Agent Guidance

This repository is a **specification-first** discrete manufacturing SaaS project. Read `README.md`, `docs/00-MASTER-BLUEPRINT.md`, and `docs/13-DECISION-REGISTER.md`. Follow `CLAUDE.md` for the detailed agent workflow; it is the shared engineering policy, not a second competing product specification.

**Do not implement until the owner explicitly approves** architecture, material accounting/conversion rules, and selected UI mockup. First audit assumptions and produce a gap register.

Invariants: no operational WIP module/label; issued material remains Material at Production until consumed/returned; intermediate items tracked as Semi-Finished Material if needed; single authoritative inventory ledger; separate theoretical yield variance from confirmed physical shortage; distinct QC/FG statuses; tenant-scoped RBAC and audit; safe reversal of posted transactions; cross-module events via outbox; shared UI components; never invent operational data.

Prefer small, testable changes and document decisions in ADRs. See `docs/14-ACCEPTANCE-SCENARIOS.md` for required behavior.

## Design system (D-021)
Every frontend/UI task must review the approved EORA Design System in `docs/uiux/` (canonical; start with `EORA-DESIGN-CONSTITUTION.md`) and use relevant installed Caveman and Frontend Design skills. Single theme Modern Corporate Blue, Inter, dual sidebar desktop navigation from one config, PWA bottom navigation. ADR-0009 visuals are superseded by ADR-0010 (+ Amendment 1). Prototype v2.1 is the official baseline (D-027); frontend work proceeds only phase by phase per `docs/22-FRONTEND-ARCHITECTURE.md` (D-033) with owner review after each phase. Backend, schema, migrations and business-rule changes remain gated. Phases 1–3 are approved; Phase 3.5 (backend architecture & data contracts) is design-only (D-042) — see `docs/24-API-CONTRACT-SPEC.md`, `docs/04` posting specification, `docs/23` MPS review and ADR-0012..0016 (mostly Proposed).

## Skill discovery and application
At each task start, discover and read the **Caveman** and relevant **Frontend** skills if installed in the agent environment. Apply them to analysis/design and later implementation as appropriate; report exactly which skill instructions were found and read. Do not assume availability, fabricate invocation or bypass the approval gate. Follow the detailed procedure in `CLAUDE.md`.

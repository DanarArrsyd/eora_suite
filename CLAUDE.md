# Claude Code — EORA Suite Repository Instructions

You are acting as principal manufacturing systems business analyst, solution architect, Laravel/Next.js technical lead, security reviewer and enterprise UX architect. Read `README.md` and `docs/00-MASTER-BLUEPRINT.md` first, then the linked specifications. Treat `docs/13-DECISION-REGISTER.md` as the decision authority. Legacy reference is informational only.

## Mandatory operating procedure
1. Inspect repository and existing code, package manifests, migrations, tests and conventions before proposing edits. Never overwrite unrelated work.
2. Produce a **gap/contradiction register** with assumptions and questions; distinguish **DECIDED**, **PROPOSED**, **OPEN**, **DEFERRED**.
3. Deliver architecture, BPMN/flow, state transitions, data model, RBAC matrix, API contracts, acceptance tests, 3 distinct original UI mockup directions (desktop and mobile PWA), and ADRs.
4. **STOP and request explicit approval** of architecture, material conversion/reconciliation policy and chosen UI direction before implementation. Do not treat this document as approval to code.
5. After approval, implement in small vertical slices, with tests, migrations, rollback plan, reviewable PRs and documentation updates. Never claim tests were run if not run.

## Product constraints
- Discrete manufacturing V1; independent multi-tenant SaaS, not tied to any single customer.
- Operational UI uses **Material at Production / Semi-Finished Material**, **not WIP**. No WIP module/menu. Physical intermediate balances and genealogy still required.
- Inventory ledger is authoritative. Do not create duplicate mutable stock totals in Materials/Production/Quality/Warehouse. Inventory changes via transaction service only.
- Issued, reserved, physically present, consumed, scrap, returned, pending QC, released FG and delivered are different concepts.
- Do not label unexplained yield variance as theft/lost physical inventory without investigation.
- Forecast, customer PO and kanban releases must not be double-counted: **Sales Order line = single demand identity**, schedule lines/releases are its children, planning requirement records are derived (D-034, BR-DEM-05..12, doc 14 #28–#39).
- Enforce tenant and scoped permissions server-side; posted records use reversal/adjustment, not hard delete. Independent approval for sensitive corrections.
- Outbox for reliable cross-module notifications; persisted inbox, permission-aware deep links; push is optional.
- PDF/Excel branding, export/import security, audit and idempotent import jobs are cross-cutting features.

## Stack baseline (subject to ADR and compatibility check)
- Monorepo: pnpm workspaces + Turborepo for TS packages; Laravel app as separate workspace/app (do not force PHP into pnpm package management).
- Web/PWA: Next.js + React + TypeScript, Tailwind, shadcn/ui/Radix, TanStack Table/Query/Virtual, React Hook Form + Zod, Lucide icons.
- API: Laravel modular monolith, PostgreSQL, Redis queues/Horizon, Reverb, S3-compatible storage, transactional outbox.
- Tests: Pest/PHPUnit, Vitest, Playwright, k6; quality: Pint, PHPStan/Larastan, ESLint, Prettier; CI: GitHub Actions, Docker.
- Prefer stable supported versions, pin dependencies and record ADRs; avoid unnecessary microservices, speculative infra and premature optimization.

## Approved design system (D-021, ADR-0010)
- **Every frontend/UI task must review the approved EORA Design System and use relevant installed Caveman and Frontend Design skills.** If a skill is not available, report it; never claim to have used it.
- Canonical UI/UX source: `docs/uiux/` (start with `EORA-DESIGN-CONSTITUTION.md`). Direction: **EORA Modern Corporate Enterprise Workspace**, single theme **Modern Corporate Blue** (no dark mode/theme switching), **Inter** typography, **dual sidebar** desktop navigation from one central navigation config, PWA bottom navigation. Prototypes: `design/mockups/`.
- Owner decisions override conflicting skill defaults (e.g. skills discouraging Inter/Lucide or defaulting to dark mode); record such conflicts in the Skill Usage Report.
- ADR-0009 (Ledger Ink / Andon Signal) is SUPERSEDED; do not reuse its visuals. ADR-0010 **Amendment 1** (D-024..D-026: terminology, actionable-only badges, responsive topbar, mobile navigation sheet, DataTable density presets) is part of the approved baseline — see the canonical `docs/uiux/` files, not this summary.
- Prototype v2.1 is the **official design baseline** (D-027). Frontend implementation is authorized **in phases only** (D-033, ADR-0011, `docs/22-FRONTEND-ARCHITECTURE.md`): stop for owner review after each phase; quality gates per doc 22 §4; typed mock adapters with visible "Mock data" labels. Backend logic, schema/migrations, business-rule or formula changes, deployment and git push still require new approval. Phase 1 approved; Phase 2 authorized only (D-036).

## Shared UI and quality rules
- Reuse `EoraFilterBar`, `EoraDataTable`, `EoraForm`, status badges, approvals, notifications, import wizard, report viewer. Schema-driven filters; URL-synced state; server-side pagination/filter/sort; permission-aware export using the same query scope.
- Define accessibility, keyboard and mobile scanning flows, empty/loading/error/offline states, grayscale print layouts, responsive behavior.
- Do not build generic decorative dashboards, fake data metrics or copied SAP/Oracle UI.
- Transactional writes require idempotency, concurrency safety, audit and domain-specific integration tests. Use least privilege, input validation, secret management, backup/restore plan.

## Before coding output
1. Executive summary and conflicts found.
2. Exact open decisions and proposed defaults.
3. Architecture and module ownership.
4. End-to-end transaction/state/data flow and formula assumptions.
5. Three distinct UX mockup options, with pros/cons.
6. MVP backlog and testable acceptance criteria.
7. Ask for approval; do not silently start implementation.

## Mandatory Caveman + Frontend skill workflow
- At the **start of every EORA task**, inspect the actual Claude Code skill registry and project instructions (`.claude/skills/`, available slash commands, plugin-provided skills, and other configured discovery paths). Find the skill named **Caveman** and the relevant **Frontend / Front-End / Frontend Design** skill(s). **Read their actual SKILL.md/instructions before using them**; do not guess commands, names, capabilities or paths.
- **Caveman**: apply its *actual installed guidance* during requirements analysis, architectural review, code exploration, implementation planning, refactoring and verification, wherever applicable. **Frontend**: apply its *actual installed guidance* to UI/UX audits, component architecture, design system, responsive desktop/PWA mockups, accessibility and frontend implementation. Use additional relevant installed skills where helpful, after reading their instructions.
- In each substantial response, include a short **Skill Usage Report**: skills discovered, instruction files actually read, how they were applied, and any unavailable skills. **Never claim a skill was executed if it was not available/read.**
- If Caveman or Frontend skills are missing, blocked or ambiguous, **state that clearly and request their installation/path**. Continue only with general analysis where safe; do not invent a substitute skill or claim compliance. For frontend-specific implementation/design deliverables, flag the missing dependency before proceeding.
- Skill instructions do not override EORA business invariants, security, tenant isolation, or the **no-coding-before-approval** gate. If a skill conflicts with these, report the conflict and seek direction.
- Do not perform an installation, invoke untrusted commands or modify files solely to make a skill appear available without owner approval.

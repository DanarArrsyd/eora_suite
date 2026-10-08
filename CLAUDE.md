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
- Forecast, customer PO and kanban releases must not be double-counted.
- Enforce tenant and scoped permissions server-side; posted records use reversal/adjustment, not hard delete. Independent approval for sensitive corrections.
- Outbox for reliable cross-module notifications; persisted inbox, permission-aware deep links; push is optional.
- PDF/Excel branding, export/import security, audit and idempotent import jobs are cross-cutting features.

## Stack baseline (subject to ADR and compatibility check)
- Monorepo: pnpm workspaces + Turborepo for TS packages; Laravel app as separate workspace/app (do not force PHP into pnpm package management).
- Web/PWA: Next.js + React + TypeScript, Tailwind, shadcn/ui/Radix, TanStack Table/Query/Virtual, React Hook Form + Zod.
- API: Laravel modular monolith, PostgreSQL, Redis queues/Horizon, Reverb, S3-compatible storage, transactional outbox.
- Tests: Pest/PHPUnit, Vitest, Playwright, k6; quality: Pint, PHPStan/Larastan, ESLint, Prettier; CI: GitHub Actions, Docker.
- Prefer stable supported versions, pin dependencies and record ADRs; avoid unnecessary microservices, speculative infra and premature optimization.

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

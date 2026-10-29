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
- Prototype v2.1 is the **official design baseline** (D-027). Frontend implementation is authorized **in phases only** (D-033, ADR-0011, `docs/22-FRONTEND-ARCHITECTURE.md`): stop for owner review after each phase; quality gates per doc 22 §4; typed mock adapters with visible "Mock data" labels. Backend logic, schema/migrations, business-rule or formula changes, deployment and git push still require new approval. Phases 1–3 approved (D-036, D-037, D-042). **Phase 3.5 Backend Architecture & Data Contract Readiness** authorized for design only (D-042): no Laravel code, database creation, migrations, posting implementation, Redis integration or deployment. Supply classes: Firm Available / Projected / Pending QC (never netted or promised, D-043); calendar ISO Monday–Sunday, tenant → plant override, explicit production date vs calendar date (D-044). **Phase 3.5 approved (D-045)** — backend baseline: docs 04, 17, 23, 24 and ADR-0012..0016; MPS gates approved (D-046), OpenAPI tooling Scramble + openapi-typescript with compatibility check (D-047), `MAT_TRANSFER` (D-048), numbering per tenant/plant/type/year without gapless promise (D-049). Backend slices B0–B10 (doc 11) are delivered in milestone order M1–M7 (D-054, doc 27); each milestone needs one owner approval (scope + acceptance) before code. O-003, O-007, O-012, O-013, O-015 decided 2026-10-11 (D-059..D-063); O-021 stays OPEN; other open items have deadlines (D-065) — never invent defaults for them.
- **Backend status:** **B0 Foundation COMPLETED** (D-051, D-052, D-053: `apps/api`, Laravel 13, merged to `main` as `c19be09`, CI green). **M0 decisions recorded 2026-10-11** (doc 27, D-054..D-065): B1 decisions (doc 25 v18, D-057) and B2 decisions (doc 26 v14, D-058) approved; **D-B1-24 signed tenant context APPROVED** (D-055, ADR-0001 Amendment 2). **M1 Access (B1) scope approved 2026-10-11 (D-066, doc 28)** — M1 accepted (D-071). **M2 Core master data COMPLETED (D-095, 2026-10-29; doc 29 §6)**. **M3 Ledger (B3) is proposed only (doc 32)** — ledger, posting and business migrations need the owner's M3 approval before code. RLS policies use the signed context `tenant_id = (SELECT c.tenant FROM ctx_api.context() c)`; verifier `PARALLEL RESTRICTED`; runtime roles `log_parameter_max_length = 0`; exact `SECURITY DEFINER` allowlist in CI; libpq ≥ 17 via apt.postgresql.org (D-056); RLS is never tested as a superuser. Slices use feature branches + PRs, never auto-merge. Backend setup: `apps/api/README.md`.
- **Git:** repository `github.com/DanarArrsyd/eora_suite` (private, `main`, D-050); feature branches + pull requests for slices (D-051). Push only when the owner instructs for that task; never force-push, rewrite history, `reset --hard` or `clean -fd` without explicit instruction; run a secret scan before every push. Netting: weekly MPS bucket with daily dates kept (D-041); revisions never move shipped quantity (D-038); blanket remainder = Unscheduled Blanket Balance (D-039).

## Shared UI and quality rules
- Every change after M2 step 3a follows `docs/30-CHANGE-PLAYBOOK.md` (D-077): classify it, change it in its single source of truth, decision/BR row when behaviour changes, never edit a merged migration, keep history by versions/effective dates, tell users via the milestone UAT script.
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

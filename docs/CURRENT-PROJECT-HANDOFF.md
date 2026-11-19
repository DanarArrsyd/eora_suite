# Current project handoff — 2026-11-19 (updated after D-136 slice)

Read this before resuming implementation. This is a dated handoff, not a replacement for the decision register, scope approvals or live Git/runtime checks.

## Current state and authorization

- **2026-11-19 later:** PR #95 is **merged** (main `a9708f1`). D-137 UI consistency slices 1–4 (`docs/review/2026-11-19-UIUX-AUDIT.md` §6) are on branch `feat/ui-consistency` as a pull request, not merged. New migration `2026_11_19_005100_create_list_views` (personal saved views). Open follow-up: Daily Recovery export (needs an export job for the computed view).
- Active branch at handoff: `codex/material-ownership`, based on main `63a451c`. Ownership slice (D-135) plus the language/ownership-wording slice (D-136, owner approved 2026-11-19) are **committed on this branch and pushed as a pull request — not merged, not deployed**. Merge follows the usual rule: CI green and review done first.
- Owner decisions 2026-11-19: **D-136** UI/API/exports/docs English, role guides Indonesian. Release condition in doc 37: keep the plant-wide planning gate; do **not** apply migration `2026_11_18_005000` to the operational database before owner-aware planning ships. Lot date `DDMMYY` kept. Review and status per finding: `docs/review/2026-11-19-CODEX-OWNERSHIP-AND-LANGUAGE-REVIEW.md`.
- Local user-facing web at `http://localhost:3100` remains the previous release. API at `http://localhost:8000/api/v1/health/ready` and web login returned 200 at handoff; Docker services were healthy. Recheck before making current-state claims. The ownership migration has **not** been applied to the operational database.
- Browser verification used an isolated build/port 3101 and synthetic fixtures. Temporary build was removed afterward. Do not connect the new receipt UI to the old API: owner fields would not be supported by that release.
- Preserve unrelated user work: `docs/master_data.backup-2026-11-17.xlsx` is user-modified; `.claude/` and `poc/` were already untracked. Do not overwrite, stage indiscriminately or clean these.

## Established factory workflow

1. PPIC/QC receives coil (kg), sheets (sheet count), or other material in its configured unit. Receipt increases the single inventory ledger and records sender/supplier separately from owner.
2. RM issues material to production, with its lot and validated conversion. **Issued is not consumed**. Unconsumed material stays Material at Production; reusable intermediate blanks are Semi-Finished Material. No operational WIP module/label.
3. Production may use remaining material/intermediate lots from earlier shifts/dates. Record actual source lots and quantities; do not reset or invent stock when a shift ends. Keep production/report dates and lot genealogy distinct.
4. Production Good goes to Before Check / PENDING_QC under inspection rules, not directly to unrestricted FG. QC Accepted awaiting warehouse receipt and FG Received/Available remain distinct. Planner sees these separately; Before Check gives provisional production coverage, never a shipment promise.
5. Final inspection reclassifies production Good into accepted/NG. FG warehouse receipt makes released goods available through existing rules, then delivery consumes eligible stock.
6. SPH is the owner's operational **good pcs per running machine hour** (600 means 600 good pcs), despite the Stroke Per Hour name. Do not substitute total strokes or full-shift elapsed time.
7. Beam Inner blank is interchangeable for downstream RH/LH allocation. Plate Grip creates fixed paired RH/LH output: corrected sheet dimensions `3.2 × 455 × 1219`, example conversion 18 RH + 18 LH per sheet. For 350 LH, owner chooses completing 20 full sheets (360 RH + 360 LH theoretical), preserving surplus. Good RH/LH may differ due to NG; do not freely reallocate paired output between sides. A 550 total-good example means 275 RH + 275 LH.

## D-135 ownership implementation

Canonical: [scope](37-MATERIAL-OWNERSHIP-SCOPE.md), [ADR-0020](adr/ADR-0020-material-ownership-foundation.md), decision D-135 in doc 13, BR-OWN rules in doc 15. Full test evidence and screenshots: [validation](uat/2026-11-19-MATERIAL-OWNERSHIP-VALIDATION.md).

- DPM/SJM/AMI/SUP are customers. Each may supply its own assets; company-purchased material can serve eligible compatible customer work. Supplier/sender, material owner and production customer are different identities.
- One production posting and all its Good/NG/scrap lots use **one owner**. Different runs of a WO may be separated by owner. No mixed-owner posting, cross-customer exception or ownership transfer in this slice.
- Immutable lot ownership drives ledger/balances and follows splits, consumption, outputs, QC, FG receipt, returns, delivery and reversal. Customer assets require lot control. Customer scrap also requires a lot-controlled scrap item.
- Customer assets may only serve a WO/delivery for that customer. Explicit `productionCustomerId` supports unpegged WOs; a linked schedule's customer must agree. Company-owned material still follows BOM/spec compatibility.
- New receipt lots: `RM-{ownerCode}-{DDMMYY}-{sequence}`. New customer-attributed finished output: `FG-{productionCustomerCode}-{DDMMYY}-{sequence}`. Atomic daily counters span items within tenant/type/code/day; genealogy comes from actual links, not matching suffixes. Company code comes from configured company master, never hardcoded. Old numbers/history/idempotency hashes remain compatible; intermediate/scrap/unattributed outputs retain legacy numbering.
- Customer inactivation prevents new customer receipt/WO attribution, but existing lots and authorized production can still be split/processed/returned/checked with their original owner.
- Migration `apps/api/database/migrations/2026_11_18_005000_add_material_ownership.php` adds owner identities, WO customer, daily counters and database guards. Forced tenant RLS and runtime least privilege remain. Rollback refuses existing customer-asset history; use a reviewed forward-compatible rollback after assets exist.
- Main implementation: `Inventory/Application/MaterialOwnership.php`, `InventoryPostingService.php`, `LotNumbers.php`, Receipt/Production/WorkOrder/Delivery controllers and inventory queries. UI extends existing material receipt/stock/lot and WO forms; no alternate design system.

## Critical integration boundary — next priority

**Owner-aware automatic planning is NOT implemented.** Existing aggregate calculations would incorrectly treat restricted assets as shared stock. Until resolved, a plant with positive customer-owned physical balances refuses new aggregate MPS/MRP/Daily Recovery calculations/actions with `409 OWNER_PEGGING_REQUIRED`. Saved MPS/MRP snapshots and linked WOs remain readable; manual customer WOs remain possible. Do not remove the guard merely to make the screen work.

Recommended next work, requiring an explicit scope decision before implementation:

1. Design owner/customer-aware planning and pegging across MPS, MRP and Daily Recovery. Customer assets cover only their own demand; shared company stock is counted once across customers. Preserve production-customer attribution on generated WOs and atomic availability protection.
2. Preserve D-134 combined coverage without double counting: Before Check → QC Accepted → FG receipt must not increase total coverage; QC NG reduces it. Net remaining WO output only once, respecting lot/customer/owner eligibility and existing reservations/deliveries. Keep old plan snapshots immutable.
3. Add integration tests for two customers competing for company stock, restricted stock exclusion, paired blanking surplus, partial carry-over, QC transitions and concurrent creation/retry. Replace the temporary guard only for paths proven owner-aware. Review UI states with the owner and run factory UAT before operational rollout.
4. Then build PPIC monthly Material Monitoring and NG provenance. Selected table: **Customer | Part Name | Part No. | Spec Material | IN RM | OUT ke Produksi | Terpakai | Good | NG | Miss**, with expandable event/lot details and owner filters. Global/customer availability needs an explicitly agreed numerator/denominator; do not invent a percentage.
5. Production NG, line/trial NG and final QC NG need distinct source records without duplicate entry/counting. The final choice of who enters trial NG is unresolved. Final QC NG reclassifies reported production Good, rather than becoming extra material consumption.
6. Miss is unexplained theoretical yield variance, **not confirmed physical shortage**. For a completed, reconciled cohort: theoretical from actual consumed material and snapshot conversion minus reported Good minus production NG. Account for residual material, reusable blanks and prior lots before closing. Keep physical shortage as a separately approved adjustment. Period activity and current cohort state must be distinguishable.

Doc 35 / `design/mockups/05-material-monitoring.html` contains design proposals and simulated data, not a working monthly report. Older proposed mixed-owner wording there is superseded for this first slice by D-135/doc 37. Daily Recovery D-134 is documented separately in doc 36 / ADR-0019; its existing coverage behavior remains authoritative except for the temporary ownership gate.

## Verification and reproducibility

- After the D-136 slice (2026-11-19, Claude Code): API **493 passed** (one `AccessControlTest` statement timeout in identity cleanup; rerun 25/25 passed), mocked browser **244 passed**, frontend unit **130 passed**, typecheck, ESLint, Prettier, Pint, PHPStan clean. Factory UAT still outstanding.
- Complete API suite via PgBouncer: **493 passed, 20,000 assertions**, including tenant/pool isolation. Final ownership-only run: **3 passed, 213 assertions**, including inactive-customer completion.
- Complete mocked browser suite: **243 passed**. Frontend unit: **130 passed**. TypeScript, lint, PHPStan, Pint, web build, formatting and diff whitespace checks passed. Receipt responsive/axe checks covered 1920/1440/1024/768/390/375 px. Factory UAT remains outstanding.
- Migration round trip used non-superuser owner on **`eora_test` only**. Rollback with customer history was explicitly refused and history remained. Never reset operational data for verification.
- Run backend suites sequentially on the shared test DB: concurrent suites can interfere with the global invitation mail queue. Do not weaken the context latency threshold; the isolated and final full run passed it unchanged.
- Mocked browser fixtures fence unmatched `/api/v1/` requests with explicit test-only 503 and warn, preventing omitted mocks from expiring a synthetic session against the real API. Real-stack tests use base Playwright, without this fence. Add proper fixtures when expanding coverage.
- Use repository-pinned **`corepack pnpm` 12.10.1**; bare pnpm previously resolved to a different fallback. Next family aligned to **16.4.6** to unblock the security-gated build. Remaining Zod/Undici/minimatch audit findings are recorded, not independently assessed/remediated; do not claim security audit clean.
- QA isolation options: `EORA_NEXT_DIST_DIR` and `EORA_TEST_WEB_PORT` (default port 3100). Do not disrupt the user's running web for test builds. Generated OpenAPI client was refreshed.

## How the next session should resume

Read AGENTS.md/CLAUDE.md, this file, doc 13 D-134/D-135, docs 36/37, ADR-0019/0020 and the validation note. Inspect Git and runtime before edits; the working tree itself contains the implementation. Use graph tools first for code discovery. Review approved EORA UI constitution for UI work. Preserve blue/Inter/shared components, one authoritative ledger, signed tenant RLS, server RBAC, audit, reversal and idempotency. Keep implementation state, test evidence and actual deployment separate. Do not repeat the owner-separation question: it is already answered.

Skills reviewed for this handoff: installed Caveman and Frontend Design instructions; applied concise writing and existing design constraints. This task changes documentation only, not application behavior.

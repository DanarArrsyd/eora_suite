# Review — Codex material-ownership slice (D-135) and system language — 2026-11-19

Reviewer: Claude Code. Scope: uncommitted working tree on `codex/material-ownership` (base `63a451c`) plus the language of the shipped UI (incl. PR #94 Daily Recovery). Read-only review: no application code changed, tests **not** re-run in this review (handoff claims 493 API / 243 browser / 130 unit passed).

Status keys: **DECIDED** (register says so), **PROPOSED** (this review recommends), **OPEN** (owner must decide).

## 1. Verdict

- Ledger/DB design of D-135 is sound: immutable lot owner (trigger), owner derived from lot not client, one owner per production posting (deferred constraint trigger), customer asset only to its own WO / delivery / allocation, forced RLS on `daily_lot_counters`, refusing rollback once customer assets exist.
- **Not releasable to the factory as is** (L-severity items aside): the temporary planning gate stops MPS/MRP/Daily Recovery for a whole plant as soon as one customer-owned lot is on hand (F-1).
- System language is inconsistent: UI is English except Daily Recovery (Indonesian/mixed); role guides are Indonesian except the Daily Recovery section (English); ownership vocabulary has ~8 variants for 2 concepts.

## 2. Functional / technical findings

| # | Sev | Finding | Where | Proposed fix |
|---|---|---|---|---|
| F-1 | P1 (release blocker) | `requireAggregatePlanning` returns `409 OWNER_PEGGING_REQUIRED` for the **whole plant** once any `stock_balances.ownership='CUSTOMER' and qty>0`. First DPM/SJM receipt stops MPS, MRP and Daily Recovery for every customer. Handoff acknowledges; nothing prevents deploying it. | `Inventory/Application/MaterialOwnership.php:45` | Do not apply migration to the operational DB until owner-aware planning (handoff next-step 1) lands, or scope the gate to items that have customer stock. OPEN: owner picks. |
| F-2 | P2 | Issue page lists every lot of the item, including lots of **other** customers. Selecting one is refused only by the DB trigger. | `modules/materials/flow.tsx:410` | Filter options by WO production customer (company lots + own-customer lots); show owner on every option, not only customer lots. |
| F-3 | P2 | Trigger messages reach users raw and lower-case via `POSTING_REFUSED` (e.g. "customer-owned material only serves its owner customer work order"). | migration `eora_guard_stock_owner`, `InventoryPostingService.php:956` | Map each guard to a coded problem with a user sentence, or validate in the controller first (as Receipt already does). |
| F-4 | P3 | `INBOUND_SCHEDULE.blank` got `ownerCustomerId` but there is no field and the inbound API has no owner. Dead key; receipt from inbound line cannot preset the owner. | `modules/materials/resources.tsx:569` | Remove the key, or add owner to inbound (new scope). |
| F-5 | P3 | API exposes `ownership` / `ownerCustomerId` filters on stock, lots, receipts; UI tables add only a column, not a filter. PPIC monitoring needs owner filter. | `StockQuery.php:90`, `inventory/resources.tsx:87` | Add schema-driven owner filter (EoraFilterBar) to the 3 tables. |
| F-6 | P3 | `eora_check_production_owner` is a per-row deferred trigger that recounts all movements of the document: O(n²) per posting. Fine for today's small postings. | migration | Note only; statement-level or per-document dedupe later. |
| F-7 | P3 | Role guides not updated for receipt owner, WO production customer and the new 409 block (D-126 requires guides). | `docs/guide/MAT.md`, `PPC.md`, `INV.md` | Add short Indonesian steps. |

## 3. Language findings

Current state (observed, not decided anywhere in doc 13):

- Web UI, API messages and docs: English. Only `modules/ppic/daily-recovery.tsx` is Indonesian/mixed.
- Role guides `docs/guide/*`: Indonesian (D-126) — but `PPC.md` §"Daily Recovery Planner (D-134)" is English.
- Owner/PPIC speak Indonesian; PPIC monitoring headers requested mixed: "OUT ke Produksi", "Terpakai" (D-133).

| # | Sev | Finding | Example | Proposed |
|---|---|---|---|---|
| L-1 | P1 | No decision on UI language. Two screens next to each other speak different languages. | Work Orders: "Work order", "Draft"; Daily Recovery: "Kebutuhan SPK net", "Simpan rencana shift", "Tindakan" | OPEN → register a decision: (a) UI English, guides Indonesian (matches 95 % of code) **recommended**; (b) UI Indonesian; (c) i18n EN/ID per user (new scope, larger). |
| L-2 | P1 | One concept, several names. Breaks "one term per thing" (constitution §4, D-024). | "SPK" vs "Work order"/"WO"; "Before Check" vs status badge "Pending QC"; "FG Gudang" vs "FG warehouse"; "Plan" vs "Rencana" | Glossary (below), then rename Daily Recovery labels. |
| L-3 | P2 | Ownership vocabulary: 8 variants for 2 concepts. | "Company stock", "Company-owned", "Company: Existing company stock", "company-purchased material", "company material only", "Customer-owned", "Customer material owner", "Owner DPM", docs also "customer assets", "restricted stock" | Column **Owner**; values **Company** / **{code} (customer)**; field **Material owner** with an explicit "Company" choice instead of "leave empty". |
| L-4 | P2 | System-internal jargon in a user message. | 409 detail: "owner-aware automatic planning … restricted stock cannot be pooled across customers"; MRP: "not netted" | "This plant holds customer material. Plan it with a customer work order: MPS, MRP and Daily Recovery cannot keep customer stock separate yet." |
| L-5 | P3 | "Supplier / sender" label, but facts and table still say "Supplier". "Sender" is undefined. | `materials/resources.tsx:135,175` | Keep **Supplier** (the delivering party); owner is its own field. |
| L-6 | P3 | "Unassigned — company material only" reads as "no person assigned". | WO facts | "None — company material only". |
| L-7 | P3 | Field hint is 3 sentences; D-126 asks short texts. | receipt owner hint | One sentence; rules go to the guide. |
| L-8 | P3 | Casing drift: "Finished good" (item type) vs "Finished Goods" (D-024) vs "Finished goods"; "Semi-finished" vs "Semi-Finished Material". | `part-resources.tsx:89`, various | Follow D-024 forms everywhere. |
| L-9 | P3 | Lot date `DDMMYY` (doc 35/37) does not sort and reads ambiguously (`011226`). | `LotNumbers.php` | OPEN: confirm with owner it matches factory labels; else `YYMMDD`. Never change existing numbers. |

### Glossary (language policy DECIDED by D-136; individual terms confirmed in the renaming slice)

| Concept | UI (English) | Guide (Indonesian) | Never use |
|---|---|---|---|
| Work order | Work order (WO) | Work order (WO) / SPK in brackets once | SPK alone in UI |
| PENDING_QC output | Pending QC (Before Check) | Before Check | two names on one screen |
| QC_RELEASED (accepted, not yet in FG) | QC released, awaiting FG receipt (badge: QC released) | QC released, belum diterima gudang FG | "belum FG", "QC Accepted" as a second name |
| Released FG stock | FG warehouse stock | Stok gudang FG | "FG Gudang" |
| Material owner | Owner: Company / {code} (customer) | Pemilik bahan | customer assets, restricted stock |
| Supplier | Supplier | Supplier | sender |
| Production customer | Production customer | Customer produksi | — |
| Unexplained yield variance | Miss (theoretical variance) | Miss | loss, hilang, shortage |

## 4. Recommended order

1. L-1 DECIDED by D-136 (UI English, guides Indonesian). Confirm individual glossary terms in the renaming slice.
2. Fix F-1 decision (release gate) before any deployment of the migration.
3. One small UI slice: L-2..L-8 + F-2, F-4, F-5 (labels/filters only, no business rule change).
4. F-3 coded problems + F-7 guides.
5. Then owner-aware planning (handoff next-step 1), which also removes F-1.

## Skill usage

Caveman (`~/.claude/skills/caveman/SKILL.md`) and Frontend Design (`frontend-design` plugin 1.1.1) read and applied: terse findings; UI-copy rules (one term per thing, plain user words, errors that say how to fix). codebase-memory index built (fast mode). No skill conflict with EORA invariants found.

## 5. Renaming slice — status (owner approved 2026-11-19, "Setuju, kerjakan")

| Item | Status | Change |
|---|---|---|
| L-1 | DECIDED | D-136 in doc 13. |
| L-2 | Fixed | Daily Recovery UI fully English, glossary terms; QC column follows the status catalogue ("QC released, awaiting FG receipt"). |
| L-3 | Fixed | One helper `apps/web/modules/inventory/owner.tsx`: column/fact **Owner** = Company / `{code}` (customer). Receipt field **Material owner** with explicit **Company (purchased material)** choice (`leadOptions` on select fields). |
| L-4 | Fixed | 409 `OWNER_PEGGING_REQUIRED` reworded; code unchanged. |
| L-5..L-7 | Fixed | "Supplier"; "None (company material only)"; shorter hints. |
| L-8 | Partly | MRP type label uses "Semi-Finished Material". "Finished good" (singular item type) kept: D-024 forbids "Finish Good", not the singular. |
| L-9 | Closed | `DDMMYY` kept (doc 37 release section). |
| F-2 | Fixed | Issue page offers only company lots and lots of the WO's customer, each labelled with its owner. |
| F-3 | Fixed | Ownership trigger refusals mapped to user sentences in `InventoryPostingService::OWNER_REFUSALS`; database wording unchanged (DB tests still match it). API validation messages aligned. |
| F-4 | Fixed | Dead inbound key removed. |
| F-5 | Fixed | Owner + Owner customer filters on Stock Overview, RM Receipts and Material Lots. |
| F-7 | Fixed | `docs/guide/PPC.md` Daily Recovery rewritten in Indonesian; owner steps in MAT, INV, PPC. |
| F-1 | Decided | Gate kept; migration not applied to operational DB until owner-aware planning ships (doc 37 release condition). |
| F-6 | Note | Performance note only. |

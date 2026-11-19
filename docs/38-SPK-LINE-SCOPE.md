# 38 — SPK per Line (daily production dispatch) — scope for approval

**Status:** DECIDED decisions S-1..S-8 (owner 2026-11-19, D-138); slice 1 implemented (owner go 2026-11-19: "SPK slice 1 dulu"); slices 2–3 start on the owner's go. Asked by the owner after trying the Production module: when a planner makes an SPK/WO, production should see it in its own menu, shaped like `docs/MASTER_SPK_HOURS_PROD.xlsx` (SPK LINE A / B / C…), editable by the planner and released to production.

**Basis:** D-109 (WO release), D-130 (Standard Good Output = good pcs per running hour), D-134 (Daily Recovery), D-135 (material owner), D-136 (UI English, guides Indonesian), D-049 (numbering), ADR-0014 (idempotency), doc 16 (state machines), doc 18 (RBAC), doc 22 (frontend gates).

## 1. What exists today

- A **work order** (WO) says *what* to make: item, quantity, due date, BOM and routing snapshot. It is released by a second person (D-109). Daily Recovery "Create draft work order (SPK)" also makes a WO.
- Production sees a WO only (a) in the **Released work order** picker when opening a **Production Run**, and (b) read-only under **PPIC → Work Orders**. The Production module has *Production Runs* and *Production Postings*; there is no daily line schedule.
- Master data already holds what an SPK needs: **work centers** (a line), **machines** under a work center (A1 AIDA 150T…), **routing operations** with process type, work center, setup minutes and standard good output per hour (SPH), work center **available hours per shift**, plant **shifts**.

## 2. What the Excel SPK is

Sheets `LINE A / B / P SPK PRINT` (plus `SPK WELDING PRINT`, `SPK REPAIR PRINT`, `LINE spot`) are a **daily dispatch list per line**, not a work order:

| Part | Excel | EORA source |
|---|---|---|
| Header | date, line (`LINE A`, "200 s/d 80 ton"), SPK number (`2/STP/I/26`) | plant calendar date, work center, numbering |
| Line | ID part, part name, part no, process no (`2/5`), process, machine ID / name, SPH, qty SPK, material qty, priority | WO + routing operation + machine; SPH from the routing (D-130) |
| Time | cycle = 3600 / SPH; dandori (setting dies) 10; QC sample 5; need time | routing setup minutes; QC sample minutes (new); calculated |
| Load per machine | columns A1–A4: hours used; capacity 7 h / shift, 14 h / 2 shifts; balance | work center hours per shift × shifts |
| Actuals (filled by production) | act qty, actual time, plan / act OT, SPH actual, problem code | production postings and runs (never typed twice) |
| Footer | operators per shift; signatures Dibuat (Admin), Diterima (Produksi), Diketahui (HRD) | print layout; manpower is a later slice |
| Related sheets | FORM CARRY OVER, FORM SISA SPK | remaining quantity computed from postings |

**Formula to confirm with PPIC.** Machine hours in the Excel are `qty × cycle / 3600 + (dandori + QC) / 100`. If dandori and QC sample are minutes, the divisor should be 60: 10 + 5 minutes is 0.25 h, not 0.15 h, so today's sheet understates machine load. EORA will use minutes / 60 unless PPIC says the inputs are in another unit.

## 3. Concept

**SPK Line is its own document; it never replaces or duplicates a WO.**

- WO = what to make (demand, quantity, due date, materials). Unchanged.
- **SPK Line** = when and on which machine: one document per **line (work center) per production date** (per shift: see decision S-2), with lines that each point to a **released WO and one of its routing operations**, a **machine** of that line, a **planned quantity**, a **priority** and a **shift**.
- The SPK holds no stock and posts nothing. Stock still moves only through issue / production posting (single ledger). Quantities on the SPK are plans; actuals are read from postings.

### Flow

1. **Planner (PPC)** opens **PPIC → SPK Line**, chooses plant, line and date. *New SPK* starts empty or **from Daily Recovery** (its saved shift plans for that date become proposed lines) or **from the previous SPK's remaining quantities** (carry-over).
2. Planner adds or edits lines: WO (released or in production only), operation, machine of the line, planned qty, priority, shift, note. The screen calculates hours per line and **load per machine against capacity**, and flags overload and machines without SPH.
3. Planner **releases** the SPK to production (S-3). Production gets an inbox notice with a deep link.
4. **Production (PRL)** opens **Production → SPK Line**: today's SPKs of its lines, in priority order. From an SPK line, **Start run** opens a Production Run with the WO, work center and machine filled in, and the run remembers the SPK line.
5. Production posts output and consumption as today. The SPK shows **plan vs actual** per line: good, NG, scrap, run hours (from postings and the run), remaining.
6. At day end the SPK closes; lines with remaining quantity are offered to the next day's SPK (carry-over). Nothing is moved automatically.
7. The SPK prints as PDF in the Excel layout with signature boxes (Dibuat / Diterima / Diketahui).

### States (doc 16 style)

`DRAFT` → `RELEASED` → `CLOSED`, and `DRAFT` → `CANCELLED`.

- Editing a **released** SPK creates a **new revision** (old revision kept, read-only); production is notified and sees what changed (S-4). A line with a production run keeps its WO and operation; only quantity, priority, shift and note can change, and quantity can never go below what is already posted.
- An SPK closes by the planner, or automatically after its last shift ends plus a grace period (S-6).

### Calculation (per SPK line)

- `run hours = planned qty / SPH` (SPH = routing standard good output per hour, D-130; an operation with cycle seconds instead uses `qty × cycle / 3600`).
- `setup hours = setup minutes / 60` (routing), once per line unless the previous line on the same machine runs the same die (later refinement).
- `QC sample hours = QC sample minutes / 60` (new plant default, 5 minutes, editable per line).
- `machine load = Σ line hours` per machine and shift; `capacity = work center available hours per shift` (7 h default in the Excel); `balance = capacity − load`. Overload is a warning, not a block.
- An operation without SPH shows **Not calculable** (never zero).

## 4. Data model

- `spk_headers` (tenant, plant, `spk_no`, work center, production date, shift or null, revision, status, source `MANUAL | DAILY_RECOVERY | CARRY_OVER`, note, released by/at, closed by/at, audit stamps, version). One open SPK per plant + work center + date (+ shift) + revision chain.
- `spk_lines` (tenant, header, line no, work order, routing operation, machine, shift, planned qty, priority, setup minutes, QC sample minutes, note). The WO must be `RELEASED` or `IN_PROGRESS` and of the same plant; the operation must belong to the WO's routing snapshot; the machine must belong to the line.
- `production_runs.spk_line_id` (nullable) links a run started from an SPK line.
- Forced RLS on tenant, plant scope through permissions, database guards for the rules above, append-only revisions.
- Numbering (S-5, owner 2026-11-19): `SPK-{companyCode}-{DDMMYY}-{sequence}`, e.g. `SPK-STP-191126-0001`. The date is the **release** date in the plant calendar; the sequence is 4 digits, counted per tenant + company + day (atomic daily counter, like the D-135 lot numbers; no gapless promise, D-049). The company code comes from the company master, never hardcoded. A draft has no number yet (shown as *Draft*); a revision keeps its number and adds the revision (`SPK-STP-191126-0001 rev 2`).

## 5. Permissions (doc 18)

| Permission | PPC | PRL | CSA | SUP | AUD |
|---|---|---|---|---|---|
| `production.spk.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `production.spk.create` (draft, edit, revise) | ✓ | | | ✓ | |
| `production.spk.release` | ✓ (S-3) | | ✓ | ✓ | |
| `production.spk.close` | ✓ | | | ✓ | |

Starting a run from an SPK line still needs `production.run.create` (PRL).

## 6. API (sketch, doc 24 style)

- `GET /api/v1/query/production.spk` (list: plant, line, date range, status, search) and `GET /api/v1/production/spk/{id}` (header, lines, load per machine, plan vs actual).
- `POST /production/spk` (draft; optional `fromDailyRecovery` / `fromSpk` for carry-over), `PUT /production/spk/{id}/lines` (If-Match), `POST /production/spk/{id}/actions/release|revise|close|cancel` (If-Match, idempotent).
- `POST /production/runs` accepts optional `spkLineId` and fills WO, work center and machine from it.
- `GET /production/spk/{id}/print` (PDF, after O-010 branding; Excel export via the export jobs).

## 7. Screens

- **PPIC → SPK Line** (planner): list by date and line with status; editor with lines table (WO, part, process, machine, qty, priority, shift, hours) and a **machine load** panel (bar per machine: load vs capacity, balance); actions Save, Release, Revise, Close, Print.
- **Production → SPK Line** (production): today's released SPKs of the user's lines; each line shows plan, posted good / NG, remaining, and **Start run**. Phone layout: one card per line, Start run as the primary action.
- Shared list pattern, Views, formats and English labels (D-136, D-137); guides PPC and PRL in Indonesian.

## 8. Decisions (D-138)

| ID | Question | Decision (owner 2026-11-19: defaults accepted, S-5 and S-8 as stated) |
|---|---|---|
| S-1 | Is LINE A / B / C / D / P a **work center**, with its presses as machines? | Yes. Lines missing in master data are added by the Supervisor. |
| S-2 | One SPK per **line per day** or per **line per shift**? | Per line per day, each line row carries its shift (Excel style). |
| S-3 | Who releases an SPK? | The planner alone (it moves no stock; the WO itself was already released by a second person). |
| S-4 | Edit after release? | Yes, as a new revision with a reason; production notified; posted quantities protected. |
| S-5 | SPK number format? | **DECIDED (owner 2026-11-19):** `SPK-{companyCode}-{DDMMYY of release}-{0001}`, e.g. `SPK-STP-191126-0001`; daily sequence per company; number given at release. |
| S-6 | When does an SPK close? | Planner closes; otherwise automatically 24 h after its date. |
| S-7 | Excel dandori / QC divisor 100 vs 60? | 60 (minutes), confirmed with PPIC. |
| S-8 | Manpower per shift, plan OT, SPK Welding / Repair / Spot? | **DECIDED (owner 2026-11-19): deferred** to a later slice (manpower and OT need HR rules). |

## 9. Slices

1. **SPK document + planner screen + production menu (read):** tables, guards, numbering, RLS, draft/release/revise/close, load per machine, Production → SPK Line read view, inbox notice. 
2. **Execution link:** Start run from an SPK line, plan vs actual from postings, carry-over to the next day, Daily Recovery → SPK draft.
3. **Print and export:** PDF in the Excel layout (needs O-010 branding decision), Excel export.
4. **Later:** manpower per shift, OT plan vs actual, SPK Welding / Repair / Spot, same-die setup saving.

## 10. Acceptance (slice 1–2)

1. An SPK accepts only released / in-production WOs of its plant, operations of their routing snapshot and machines of its line — also through direct SQL as the runtime role.
2. Hours: 800 pcs at SPH 350 with 10 min setup and 5 min QC = 2.286 + 0.250 = **2.536 h** on A1; capacity 7 h → balance 4.464 h. An operation without SPH shows *Not calculable*.
3. Release notifies production; a released SPK appears under Production → SPK Line for PRL of that plant only (tenant + plant isolation).
4. Revision keeps the old revision read-only; a line's quantity never goes below its posted good + NG.
5. Start run from an SPK line opens the run with WO, work center and machine; postings update plan vs actual; nothing posts stock outside the existing ledger.
6. Carry-over proposes remaining = planned − posted (good + NG + scrap per policy), never negative, never moved without the planner.
7. Idempotency and If-Match on every action; e2e + axe at 1920 / 1440 / 1024 / 768 / 390 / 375 px; guides PPC and PRL updated.

## 11. Out of scope

Automatic scheduling (APS), machine-level capacity calendars and breakdowns, die change optimisation, manpower assignment, payroll / OT approval, and any change to WO release rules or stock posting.

# 40 — Business and shift calendar screen (scope proposal)

Status: **APPROVED, 2026-11-27 (D-142)** — owner: "Approve, pakai usulan"; §4 C-1..C-7 decided as proposed.
Progress: steps 1–3 built on `feat/shift-calendar` (migration `2026_11_27_005500`, `ShiftCalendar`, Calendars screen); waiting for PR review and owner merge.
Builds on D-044 / ADR-0012 / doc 04 §Time model (calendars), D-123 (working days, MPS capacity), D-138 / D-139 (SPK hours).

## 1. Why

Tenant `kmi` cannot set up its shifts. `business_calendars`, `calendar_exceptions`, `shift_calendars` and `shifts` exist since M1, but there is **no API and no screen** to write them (only tests insert rows). Every tenant today has one business calendar (created with the tenant, production day start 07:00) and **no shift calendar**, so MPS capacity shows *Not configured*, SPK and Generate SPK plan one shift without a time, and postings get no shift.

The factory schedule also does not fit the current model:

| Day | Shift 1 | Shift 2 | Shift 3 | Work time per shift |
|---|---|---|---|---|
| Mon–Thu | 06:00–14:00 | 14:00–22:00 | 22:00–06:00 | 7 h (1 h break) |
| Fri | 06:00–14:30 | 14:30–22:30 | 22:30–06:30 | 7 h (S1 1.5 h break, others 1 h) |
| Sat | 07:00–12:15 | 13:00–18:15 | 19:00–00:15 | 5 h (15 min break) |

Working days Monday–Saturday (already D-123). Today the plant runs **2 shifts**; shift 3 comes later.

## 2. Today (what the code does)

| Part | Uses | Gap |
|---|---|---|
| `BusinessCalendar::resolve` (postings: production date, shift) | business calendar day start; one set of shift times for every day | no weekday times |
| `MpsCapacity` (D-123) | work center `available_hours_per_shift` × number of shifts × working days | Saturday counted like Monday; no break |
| `SpkGenerator`, `SpkController` (D-138/139) | shift list of the day (codes only) + work center hours per shift | same |
| DB guard `eora_calendar_version_guard` | a new version of a calendar must start after today; the first may start in the past; shifts may change only before their version starts | keeps history frozen — kept as is |

## 3. Proposed behaviour

### 3.1 Screen *Calendars* (Master Data → Organisation, next to Plants & Warehouses)
Per plant (tenant default shown when the plant has no override), two tabs, built from the existing page templates (EoraDataTable, FormPage, D-140; no native select or date controls, owner UI preference):
- **Business calendar** — versions (effective from, timezone, production day start) and the **exception days** list: HOLIDAY, NON_WORKING (a Saturday off), WORKING (a Sunday worked), each with a description. A new version always starts after today (DB guard); exceptions on past dates are read-only.
- **Shift calendar** — versions (code, name, effective from) and their shifts. A shift has a code (S1…), name, and **one row per weekday** it runs: start, end, break minutes; work hours are shown (end − start − break). A weekday without a row means the shift does not run that day (e.g. S3 absent, or Sunday). Copy buttons: "same times Mon–Thu" to fill quickly.
- Editing: a version that has started is read-only; changes go into a new version from a later date (history never moves, ADR-0012).

### 3.2 Data
- New table `shift_weekday_times (shift_id, iso_weekday 1–7, starts_at, ends_at, break_minutes)`; a shift runs on a weekday only when it has a row. Existing `shifts.starts_at / ends_at` stay as the shift's default (used when a version has no weekday rows, so current behaviour and tests keep working).
- Business calendar and exceptions: no schema change; API only.
- All writes audited, RLS as the runtime role, versioned, idempotent creates.

### 3.3 Calculations
- **Shift resolution** (postings): the shift times of the production date's weekday; a shift crossing midnight still belongs to the production date of its start (Friday S3 22:30–06:30 is Friday's).
- **Capacity per work center per day** = Σ over that weekday's shifts of `min(shift work hours, work center hours per shift)`; work center hours per shift empty → the shift work hours. MPS week = Σ working days; SPK and Generate SPK use the same per-shift hours. Example 2 shifts, hours per shift 7: Mon–Fri 14 h/day, Sat 10 h/day → 80 h/week (today's formula gives 84 h).
- **Production day start** stays on the business calendar; for kmi the screen sets 06:00 from a date the owner picks.

### 3.4 Permissions
New `master.calendar.view` and `master.calendar.create` (§4 C-4).

## 4. Decisions needed from the owner

| ID | Question | Options | Proposed |
|---|---|---|---|
| C-1 | Different times per weekday | (a) weekday rows per shift; (b) one time for all days (today) | **(a)** — needed for Friday and Saturday |
| C-2 | Breaks | (a) break minutes per weekday row, work hours = length − break; (b) no breaks, use work center hours only | **(a)** |
| C-3 | Capacity per shift | (a) min(shift work hours, work center hours per shift); (b) shift work hours only, work center field dropped; (c) work center hours only (today) | **(a)** — keeps the line's own limit, Saturday becomes 5 h |
| C-4 | Who may change calendars | (a) CSA (company admin) and PPC manage, everyone with planning view sees; (b) CSA only; (c) PPC and SUP | **(a)** |
| C-5 | Production day start for kmi | (a) 06:00 from the day after approval; (b) keep 07:00 | **(a)** — shift 1 starts 06:00 |
| C-6 | Shift 3 now | (a) define only S1, S2 now; add S3 later as a new version; (b) define S3 now too | **(a)** — plant runs 2 shifts |
| C-7 | Exceptions (holidays) | (a) entered on the screen, one by one; (b) also an import template | **(a)** now, import later if asked |

## 5. Out of scope
Shift calendars per work center or per line (one per plant); overtime calendar and manpower (D-138 S-8); public-holiday feed; changing history of started versions.

## 6. Delivery (one PR, three steps)
1. **API + migration** — `shift_weekday_times` (+ rollback), permissions, endpoints: business calendar versions + exceptions, shift calendar versions + shifts + weekday times; OpenAPI regenerated; feature tests incl. RLS as runtime role, version guard, audit.
2. **Calculations** — `BusinessCalendar` weekday times, `MpsCapacity` and SPK hours per C-3, with unit tests (Saturday 5 h, Friday S3 crossing midnight, no weekday row = no shift).
3. **Screen** — Calendars page (two tabs, forms as pages), mocked + stack e2e; role guide (Indonesian) and UAT script; doc 04/13/15/23 rows.
Each step: CI green + Codex clean before merge; owner merges.

## 7. Acceptance
1. CSA or PPC creates the kmi shift calendar from a chosen date with S1/S2 and the weekday times of §1; MPS capacity shows 80 h/week per line with hours per shift 7.
2. With S1/S2 only: a posting Friday 20:00 gets S2 and Friday's production date; Friday 23:00 gets no shift (outside S1/S2) and the production date from the day start; Saturday 13:30 gets S2 and Saturday's date. Once S3 exists, Saturday 06:15 posting of Friday's S3 (22:30–06:30) belongs to Friday.
3. A version in effect cannot be edited; a new version from tomorrow can; history (production dates of existing postings) does not change.
4. A Saturday marked NON_WORKING drops out of MPS capacity and lead time counting.
5. Production day start 06:00 from the chosen date: a posting at 05:30 belongs to the previous production date, at 06:10 to the same date.
6. Users without `master.calendar.create` see the calendar read-only; another tenant's calendar is never visible (RLS).

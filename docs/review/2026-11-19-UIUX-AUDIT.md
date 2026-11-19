# UI/UX audit — live screens vs EORA design system — 2026-11-19

Reviewer: Claude Code. Method: live browser on the local validation stack (`eora_m2s2`, tenant Demo Stamping, Supervisor account), 1440 px and 390 px, computed styles measured with a script (font family, font sizes, row heights, table width vs container), plus a code survey. Screens: Stock Overview, Material Lots, RM Receipts, Work Orders, Daily Recovery Planner (Codex, PR #94). Baseline: `docs/uiux/` (constitution, component standards §4–§6, tokens). Read-only: no code changed by this audit.

Skills: Caveman (terse findings), Frontend Design (copy and hierarchy), design-critique framework (usability, hierarchy, consistency, accessibility).

## 1. Verdict

The shared shell, filter bar, data table, pagination, density toggle and column chooser are solid and consistent on the master-resource lists (Stock, Lots, Receipts, Work Orders): Inter only, 13 px cells, 12 px semibold headers, 44 px rows, 24 px/600 page titles, no page-level horizontal scroll.

The gaps are (a) **Daily Recovery** — built outside the shared list pattern, so it lacks the column chooser, sort and export and is twice as wide as its container — and (b) **formatting that each screen does by hand**: quantities, dates and timestamps look different from screen to screen.

## 2. Measured baseline (what already conforms)

| Check | Standard | Measured |
|---|---|---|
| Typeface | Inter only | Inter on 100 % of text nodes (all 5 screens) |
| Table body / header | 13 px / 12 px 600 | 13 px / 12 px 600 |
| Row height (Comfortable) | 44 px | 44 px on every screen |
| Page title | 24 px / 600 | 24 px / 600 |
| Column chooser ("Show columns") | per-user column visibility | present on Stock, Lots, Receipts, Work Orders; critical columns locked ("Always shown") |
| Density Comfortable / Compact | per-user preset | present on all list screens |
| Page horizontal scroll | never | none at 1440 and 390 px |
| Phones | table → cards | cards on Stock and Daily Recovery |

## 3. Findings

Severity: **P1** blocks the daily user task or breaks a written standard on a core screen. **P2** inconsistency users notice. **P3** polish.

| # | Sev | Finding | Where / evidence | Proposed fix |
|---|---|---|---|---|
| U-1 | P1 | Daily Recovery has **no column chooser**: 14 columns, table 2,267 px in a 1,078 px container; the page carries a note "Scroll the table right…". The deciding column (Action) is the last one, off-screen. | `modules/ppic/daily-recovery.tsx` (own `DataTable` setup, not `MasterResource`) | Add the shared column-visibility menu; mark Customer, Part, Net need, Proposed, Action as `critical`; default-hide the four supply-position columns (shown in the drawer); move **Action** right after Part (constitution §3.2 "decision first"). |
| U-2 | P1 | Daily Recovery quantity columns are flat; the standard asks **grouped columns** (two-row header) for multi-bucket quantities. | same | Group "Supply" (FG warehouse, Pending QC, QC released, Remaining WO) and "Plan" (Net need, Proposed, Material capacity, Planned total). |
| U-3 | P2 | Two headers named **"Action"** (status) and **"Actions"** (row action) side by side. | Daily Recovery | Rename the status column **Next step**. |
| U-4 | P2 | Daily Recovery has **no sort** (`sort=[]`, no-op handler), **no export**, and no filter by next step (e.g. only "Plan needed" / "Material short"). | same | Server sort on Part / Net need / Next step; ExportMenu with the same query; status multiselect filter. |
| U-5 | P2 | **Items per page** select is empty on Daily Recovery: the page default is 25, the select offers 20/50/100. | Daily Recovery pagination | Use the shared default (20) and the persisted preference. |
| U-6 | P2 | Target date lives in a separate card above the list instead of in the filter bar; the info text beside it repeats rules that D-126 says belong in the guide. | Daily Recovery | Make the date a filter field (URL-synced already); move the rule text to the guide or a "How this is calculated" disclosure. |
| U-7 | P2 | **Quantities are formatted by hand** in 75 places (`${qty} ${uomCode}`) and through `QuantityWithUom` in 2. Result: `700 PCS` (Stock) vs `150 pcs` (Daily Recovery), `44.5 KG` vs standard `44.500 kg`, `1000 PCS` without thousands separator next to `700 PCS`. | 12 module files (work-orders, materials, inventory, quality, delivery, production, warehouse, demand) | One rule: every quantity cell uses `QuantityWithUom` with the item's UOM decimals; UOM shown as stored code, one case everywhere. |
| U-8 | P2 | **Dates/times have no shared formatter**: ISO `2026-11-27` in tables, `20 Nov 2026` in pickers, raw `2026-11-19 02:57:20.170988+00` (UTC, microseconds) in the Daily Recovery footer. Six separate `Intl.DateTimeFormat` setups. | Work Orders, Receipts, Daily Recovery, admin, periods, ppic/format.ts | One `formatDate` / `formatDateTime` in the plant/tenant timezone (`19 Nov 2026`, `19 Nov 2026 09:57 WIB`), used by tables, facts and footers. |
| U-9 | P2 | Filter bar **wraps to two lines** at 1440 px on Stock Overview (8 fields) and fills the width on Lots/Receipts. Owner + Owner customer (added in PR #95) are two fields for one question. | Stock Overview, Material Lots, RM Receipts | Merge into one **Owner** select (Company + each customer); keep the bar to one line at 1440 px; rarer fields under "More filters". |
| U-10 | P2 | Table wider than the container on **RM Receipts** at 1440 px (last column cut, sticky action over it). | RM Receipts | Default-hide Location sub-line or Received-as; let the column chooser save it. |
| U-11 | P3 | Phone cards on Daily Recovery list all 13 fields, zero buckets included; Next step at the bottom. | 390 px | Card order: Part, Customer, Next step, Net need, Proposed; hide zero buckets (`emptyOnPhone`). |
| U-12 | P3 | Count label "3 part" (no plural). | Daily Recovery toolbar | `itemLabel` "parts". |
| U-13 | P3 | Saved views ("Views ▾", standard §4) are not on any list yet; users re-apply filters and columns each time. | all lists | Add after U-1..U-9; store server-side per user. |

## 4. What works well

- One shell, one filter bar, one table, one pager across modules; modules differ by configuration (standard §1).
- Honest states: permission-denied names the cause ("not available for your role") instead of a blank page.
- Status badges always carry text; zero shown as "–" with screen-reader text where `QuantityWithUom` is used.
- Column chooser and density preset already give users control over what they see — the request "pilih mau tampil apa saja" is met on master lists; Daily Recovery is the exception.

## 5. Recommended slice (needs owner scope approval)

1. **Daily Recovery on the shared list pattern** — U-1..U-6, U-11, U-12. Presentation only; no formula or planning change.
2. **Shared formatting** — U-7, U-8: `QuantityWithUom` and one date formatter everywhere; e2e strings updated.
3. **Filter bar fit** — U-9, U-10.
4. **Saved views** — U-13 (new shared capability, separate slice).

Each slice: e2e + axe at 1920/1440/1024/768/390/375 px, screenshots before/after, guide update when labels change.

## 6. Implementation status (D-137, owner approved 2026-11-19)

| Item | Status | Where |
|---|---|---|
| U-1, U-2, U-3, U-6, U-11, U-12 | Done | Daily Recovery: grouped columns, Next step after Part, column settings with default-hidden QC released / Remaining WO / Material capacity / Planned, date in toolbar, phone cards hide zero buckets, "parts". Fits 1440 px (1078 px container). |
| U-4 | Done except export | Server sort + Next step filter (`sort`, `status[]`). Export of Daily Recovery needs the computed view registered as an export job — open follow-up. |
| U-5 | Done | Page size from the shared preference (20/50/100). |
| U-7, U-8 | Done | `apps/web/components/format.ts` + `Quantity`; 75 hand-written quantities and the plain ISO dates replaced; `when()` uses the same formatter. Row accessible names (`rowLabel`) unchanged. |
| U-9, U-10 | Done | One Owner filter (`mapFilters` → `ownership` / `ownerCustomerId`); Stock filter bar one line; Stock location is a truncating text column; RM Receipts hides Posted by by default. |
| U-13 | Done | `list_views` table + `/api/v1/list-views`; Views menu (save, apply, delete) on every MasterScreen list and Daily Recovery. Shared (role) views are not part of this slice. |

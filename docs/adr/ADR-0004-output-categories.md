# ADR-0004 — Output categories and transformation posting

- **Status:** Accepted (D-011, DD-04) · **Date:** 2026-10-08

## Context
Owner requires Good Output, NG Reworkable, NG Rejected, Process Scrap, Reusable Remnant and Material Return to be separate, each with base UOM, ownership and ledger rule, without double deduction.

## Decision
### Core principle — one deduction
Input material leaves stock **once**, in the consumption leg (`MAT_AT_PROD → V_CONSUMED`). Everything that comes out of the process is a **production leg** (`V_PRODUCED → location`). Material that did **not** enter the process (remnant, return) is never consumed and therefore never "added back".

### Category rules
| Category | Base UOM | Item | Ledger posting | Location / status | Business owner (posts) | Balance owner |
|---|---|---|---|---|---|---|
| **Good Output** | pcs (integer) | FG or Semi-Finished item | Production Posting: `V_PRODUCED → QC_AREA` | `PENDING_QC` if inspection required for item/operation; else `UNRESTRICTED` at next-process `MAT_AT_PROD` (semi-finished) | Production | Inventory |
| **NG Reworkable** | pcs | same item as good | Production Posting: `V_PRODUCED → NG_AREA` | `REWORK` | Production records; Quality confirms | Inventory |
| **NG Rejected** | pcs | same item as good | Production Posting: `V_PRODUCED → NG_AREA` | `REJECTED` | Production records; Quality confirms | Inventory |
| **Process Scrap** | kg (measured) | scrap item per material grade (e.g. `SCRAP-SPCC`) | Production Posting: `V_PRODUCED → SCRAP_YARD` with reason (`SETUP`, `TRIM/SKELETON`, `HEAD_TAIL`, `PROCESS`) | `UNRESTRICTED` (quantity only; no valuation V1) | Production | Inventory |
| **Reusable Remnant** | native input UOM (kg / sheet) + dimensions | same material item, **child lot** of parent (`C-07` → `C-07-R1`) | **Lot Split** (non-transformation, conserves qty): `parent lot −x → child lot +x` at `MAT_AT_PROD` | `UNRESTRICTED` or `HOLD` until measured | Production (measures), Materials (accepts) | Inventory |
| **Material Return** | native input UOM | same lot (or child lot) | **Material Return** transfer: `MAT_AT_PROD → RM_WAREHOUSE` | `UNRESTRICTED` or `HOLD` | Materials (receives), Production (hands over) | Inventory |

### Follow-on dispositions
- **NG Rejected → scrap:** Scrap Disposition (transformation): `NG_AREA/REJECTED pcs → V_CONSUMED`, `V_PRODUCED → SCRAP_YARD kg (weighed)`. Approval per threshold.
- **NG Reworkable → rework:** Rework Order consumes `REWORK` pcs and produces Good (→ `PENDING_QC`) and/or NG Rejected; genealogy keeps original output lot.
- **Production NG vs QC Rejected:** Production NG is recorded at the run; QC may reclassify `REWORK ↔ REJECTED` or reject good output (`PENDING_QC → REJECTED`). Each is a status-change movement with actor and reason.

### Production Posting document (single atomic document per run report)
```
consume   MAT_AT_PROD/WC-PRESS1  lot C-07   −612.400 kg   → V_CONSUMED      (method WEIGHED_REMAINING)
produce   V_PRODUCED → QC_AREA   lot O-1182-01  +1,620 pcs  PENDING_QC
produce   V_PRODUCED → NG_AREA   lot O-1182-01  +12 pcs     REWORK
produce   V_PRODUCED → NG_AREA   lot O-1182-01  +9 pcs      REJECTED
produce   V_PRODUCED → SCRAP_YARD SCRAP-SPCC     +128.900 kg (TRIM/SKELETON + HEAD_TAIL)
genealogy C-07 → O-1182-01 (consumed 612.400 kg)
```
(Sample numbers for illustration only.) Remnant split and return are separate documents posted **before** consumption is measured, because measured remaining excludes them.

## Consequences
+ No category can reduce input twice; remnant/return conserve quantity.
+ Each category has a single owner and a single ledger pattern → testable.
− Scrap weighing per run/shift needed for Unexplained Material Variance to be FINAL (ADR-0005).

## Verification
Tests: sum of outputs never alters consumed; remnant split conserves; return reduces `MAT_AT_PROD` only; NG reclassification is a status movement; scrap disposition of rejected pcs requires weight; acceptance doc 14 #19–#22.

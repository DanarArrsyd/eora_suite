# ADR-0012 — Business calendar, production date and time model

- **Status:** Accepted for the calendar rule and definitions (D-044); implementation details (tables, versioning) Proposed · **Date:** 2026-10-09

## Context
Netting is weekly (D-041) while deliveries are daily; shifts cross midnight; plants may sit in different timezones. Without explicit time concepts, production date, weekly buckets and period checks drift from the shop-floor reality.

## Decision
1. Default weekly bucket = **ISO 8601 week (Monday 00:00 → Sunday 24:00)**, default timezone **Asia/Jakarta** (D-044).
2. Hierarchical configuration: **tenant default calendar → plant override** (`business_calendars` with `plant_id NULL` for the tenant default), versioned by `effective_from`; holidays/non-working days in `calendar_exceptions`.
3. Separate concepts (doc 04 §Time model): Transaction Timestamp (UTC instant), Plant Timezone, Calendar Date, Production Day Cut-off, Production Date, Shift / Shift Date, Week Start / Week End, Requirement / Due Date.
4. Production date = local date of the production day defined by the cut-off; a shift belongs wholly to the production date of its start; production date is stored on every posting and never re-derived from `posted_at` later.
5. Buckets for planning use production/requirement dates; reporting may additionally show calendar dates (CT-4 toggle).

## Consequences
+ Weekly netting, daily delivery risk and period control use one consistent definition.
− Calendar changes must not move historical buckets → versioned calendars; rebuild of read models on `calendar.changed`.

## Verification
Doc 14 #47, #48, #62; tests for DST-free zones and plants in other zones (e.g. Asia/Makassar) with the same ISO week rule.

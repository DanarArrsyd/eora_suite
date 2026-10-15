# 30 — Change Playbook (features, rules, screens)

**Status:** adopted 2026-10-15 (D-077). Applies to every change after M2 step 3a, by people and by coding agents.

**Goal:** a later change, whether a new feature, a changed business rule or a moved menu, is made in **one place**. It keeps the history of posted and approved records, is tested and reviewed like the rest, and users learn what changed. This playbook does not replace the decision register (doc 13) or the business rule catalog (doc 15). It explains how to use them.

## 1. Classify the change first
| Kind | Example | Needs owner decision? | Where it is changed |
|---|---|---|---|
| Text / label | rename a column header, fix a hint | no (unless terminology from doc 06/D-024) | the screen's resource config (`apps/web/modules/*`) |
| Screen / navigation | move a menu item, add a section | **yes** for navigation (doc `uiux/EORA-NAVIGATION-ARCHITECTURE.md`) | `packages/features/src/navigation/config.ts` only — pages never declare menus |
| Tenant setting | tolerance values, approval thresholds | no — the tenant administrator sets it; the **default** needs a decision | tenant/plant settings (effective-dated, audited; the settings store arrives with the first configurable rule — approval thresholds or B8 tolerances) |
| Business rule | when a case opens, what blocks a WO close | **yes** → D-number in doc 13, BR-* row in doc 15 | domain service + DB constraint/trigger + tests |
| Formula | GYV, PvS, conversion profiles | **yes** + ADR (ADR-0005 family) | versioned `formula_code`; old results keep their version |
| Permission / role | who may approve routings | **yes** | new migration adjusting the matrix + doc 18 |
| Data model | new column, new table | approval of the milestone scope | **new** migration (never edit a merged one) + doc 17 |
| API | new endpoint or field | covered by the milestone scope | controller + `ProblemResponses::ERRORS` + regenerated OpenAPI/typed client |

If you are unsure which row applies, treat it as a business rule: record the open question (**OPEN**, with what it blocks) and do not invent a default.

## 2. Single sources of truth (change it there, nowhere else)
| Concern | Source | Checked by |
|---|---|---|
| Decisions | `docs/13-DECISION-REGISTER.md` (DECIDED / PROPOSED / OPEN / DEFERRED) | review |
| Business rules | `docs/15-BUSINESS-RULE-CATALOG.md` (BR-* ids referenced from tests) | tests named after the rule |
| Navigation | `packages/features/src/navigation/config.ts` | e2e navigation specs |
| Permissions and role templates | permission migrations (`…_add_master_data_permissions.php` pattern) + doc 18 | `SyncTenants` (`eora:tenants:sync`) applies them to existing tenants |
| List/search resources | `config/eora.php` `queries` → `QueryDefinition` classes | `/api/v1/query/{resource}/schema` |
| Error codes per endpoint | `App\Platform\Http\OpenApi\ProblemResponses::ERRORS` | `OpenApiContractTest` |
| API contract | generated `packages/api-client/openapi.json` + `schema.d.ts` | CI "OpenAPI drift check" |
| Database invariants (tenant isolation, immutability, lifecycle) | constraints, triggers, RLS policies in migrations; definers in `database/bootstrap/*.sql` | `SchemaGuardTest`, `SecurityBaseline` allowlist |
| UI building blocks | `packages/ui`, `packages/features` (EoraDataTable, EoraFilterBar, EoraForm, …) | unit + axe tests |

A rule exists **twice on purpose** only when security needs it: in the service (a friendly 422/409 message) and in the database (it cannot be bypassed). Both change in the same PR, with a test for each.

## 3. Rules that change over time
- **Never rewrite history.** Approved or posted records are not edited to match a new rule. Versioned masters (routing versions, BOM revisions, UOM conversions, conversion profiles) get a new version with an effective date of today or later (D-073, D-B2-14). Posted documents are corrected by reversal or adjustment.
- **Tunable, not hard-coded:** a value a tenant may reasonably want different (tolerances, thresholds, lead times) is a setting with an audited change and an effective date. A value that protects integrity (maker ≠ checker, tenant isolation, no WIP label, ledger authority) is an invariant in code and in the database, never a setting.
- **Formulas carry a version.** A changed formula gets a new `formula_code`/version. Stored results keep the version they were computed with, and reports show it.
- **Removing something** means deactivating it first (status INACTIVE or RETIRED). Rows that are referenced are never deleted.

## 4. Database changes
1. Make a new migration with a working `down()`. Drop objects in reverse dependency order: functions after the tables whose triggers use them.
2. Never edit a migration after it is merged. While it is still unmerged, editing it means local databases need a manual reset, because rollback runs the **new** `down()`.
3. Every tenant table gets the composite tenant FK, forced RLS bound to `ctx_api.context()`, column grants and the audit stamp trigger.
4. A new `SECURITY DEFINER` function must:
   - be owned by a NOLOGIN role that is a member of no role;
   - have least-privilege column grants and its own tenant policies;
   - set `search_path = ''`;
   - be added to `SecurityBaseline` (allowlist) in the same PR.
5. Bootstrap SQL must stay idempotent (`CREATE OR REPLACE`, guarded grants and revokes).
6. Prove it: run the full suite twice and a `migrate:refresh` round trip, as a non-superuser.

## 5. Pull request checklist
- [ ] Change classified (§1). A decision or BR row is added or updated when behaviour changes.
- [ ] Changed only in the single source (§2); nothing duplicated in another module.
- [ ] Tests cover the new behaviour **and** the bypass, e.g. direct SQL as `eora_app`, another tenant, a narrower scope.
- [ ] Migration round trip; OpenAPI and typed client regenerated (CI drift check green).
- [ ] Frontend:
  - [ ] uses the shared components;
  - [ ] no native select or date controls;
  - [ ] empty, loading and error states present;
  - [ ] axe clean;
  - [ ] checked in the browser.
- [ ] Docs updated in the same PR (17, 18, 24, 26, nav, README).
- [ ] Secret scan; no force-push; the owner merges.

## 6. Telling users what changed
- **Every PR that changes visible behaviour** adds a short entry to the milestone's UAT script (`docs/uat/M<n>-UAT.md`): what changed, how to try it, and the expected result. The owner accepts the milestone by running it.
- **Rule or formula changes** show the effective date on screen (version history panels) so a user sees which rule applied to which record.
- **Pilot release gate (O-021):** before the first pilot, run one documented accessibility pass with:
  - screen readers: NVDA and VoiceOver;
  - real touch devices;
  - Windows High Contrast;
  - 200 % and 400 % zoom.

  Fixes go into the shared components, so every screen benefits.

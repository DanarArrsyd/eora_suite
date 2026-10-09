# 25 — B1 Multi-Tenancy & Security: Architecture Review

**Status:** ARCHITECTURE REVIEW v6 (2026-10-09; PR #2 review remediation). Owner approved the **direction** as the basis for final documentation review (D-053). **Implementation NOT authorized**: no code, migrations, authentication, tenant isolation, RBAC or RLS until explicit owner approval of B1 and of the decisions in §17.
**Basis (unchanged):** ADR-0001 (+ Amendment 1, D-053), ADR-0006, ADR-0007, ADR-0012, ADR-0013; docs 05, 17, 18, 24; doc 11 B1 slice (exit: T-ISO-01..05 green).
**Starting point:** B0 COMPLETED — PR #1 merged as `c19be09`, post-merge CI run 37942703992 green.
**Priority:** tenant isolation > authorization scope > authentication/session > audit > convenience.

## 0. Review log
| # | Version | Finding | Resolution |
|---|---|---|---|
| R-1 | v2 | `tenants` called "no RLS" and also given a policy | RLS enabled **and forced**; SELECT policy for `eora_app`; INSERT/status UPDATE only for `eora_provisioner` (§2.3) |
| R-2 | v2 | after `set_config(…, true)` + commit the GUC is `''` (verified on PostgreSQL 18.6) → `::uuid` errors on reused/pooled connections | all policies use `(SELECT NULLIF(current_setting(…, true), '')::uuid)`; ADR-0001 Amendment 1, doc 17 aligned |
| R-3 | v2 | memberships self-select OR-ed with the tenant policy leaked other-tenant memberships | self-select only without tenant context |
| R-4 | v2 | Compose/CI use the superuser → RLS bypassed → false-positive tests | runtime + groups B/C use non-superuser roles; bootstrap assertion |
| R-5 | v3 | outer test transaction hides boundary bugs | B/C tests without outer transaction; unique tenants per test |
| R-6 | v3 | `users` fully readable by `eora_app` | profile vs credentials split (§2.2) |
| R-7 | v3 | provisioning via owner | `eora_provisioner` under forced RLS (§2.5) |
| R-8 | v3 | session user loaded before any context | request transaction sets context from the server session before auth (§6) |
| **R-9** | v4 (Codex P1-01) | `auth_api.login_lookup` ran as a definer that owns only `auth`, but read email/status from `public.users`, which has **forced RLS requiring a context that login does not have** → zero rows/permission error → login impossible | login-relevant identity (normalized email, account status, credentials) moved into `auth.identities`; login reads **only** the `auth` schema; `public.users` keeps profile data and is never needed before login (§2.2) |
| **R-10** | v4 (Codex P1-02) | `provision_admin_user` stored only the invitation hash and returned `user_id`; external I/O is banned in the transaction → the raw secret reached nobody → first admin could never log in | provisioning only records the invitation **request** + outbox event; an after-commit **delivery worker** with its own role issues the secret, stores its hash and sends it; raw secret exists only in worker memory and the message to the owner of the address (§2.4) |
| **R-11** | v4 (Codex P1-03, **critical**) | `eora_app` had `EXECUTE` on `issue_password_reset` (returns the raw token) and `consume_password_reset` → any SQL injection as `eora_app` could mint and consume a reset token for a privileged email → account takeover | `eora_app` has **no** privilege on any auth function or table; token **issuance** is possible only for the delivery role (which cannot consume); **consumption** only for the web auth role (which cannot issue or read tokens); the web path can only *request* a reset (no token returned) (§2.3) |
| **R-13** | v5 (owner gate A) | `set_own_password` authorized "only for `ctx_user`", but any role can call `set_config('app.user_id', <victim>, true)` — custom settings are caller-controlled → an SQL injection in an auth route could set any user's password | **removed from B1**; password change = email-confirmed reset flow (§2.3) until a DB-verifiable step-up exists (D-B1-23); rule: **no `auth_api` function accepts `app.*` settings or caller-supplied ids as proof of identity** (§2.8) |
| **R-14** | v5 (owner gate B) | `record_login_result(user_id, success)` let the caller report "success" and reset lockout counters | replaced by `record_login_failure(user_id)` only (append to `auth.login_failures`); lockout = failures in a sliding window, cleared **only** by expiry or by consuming a reset secret; nothing a caller can report lowers it; locked accounts get no hash from `login_lookup` (§2.2) |
| **R-15** | v5 (owner gate C) | `issue_invitation_secret(tenant_id, membership_id, user_id)` and `issue_reset_secret` trusted caller-supplied targets | issuance takes **only** the id of a request recorded by an authorized path and derives target identity, email, tenant and membership from stored rows; validates status, expiry, identity state, membership `INVITED` + tenant/user match, per-target caps (§2.3, §2.4, §2.8) |
| **R-16** | v5 | `pending_activations(user_id)` returned another user's tenant/membership ids to any web caller | removed; activation completes in the acceptance request, failures surface to the inviting admin (§2.4) |
| **R-17** | v5 | general: `app.*` settings are writable by every role, so GUC-based RLS stops application **bugs**, not arbitrary SQL; v4 also recommended PDO emulated prepares (client-side interpolation, multi-statement simple-query protocol) | stated explicitly (§5.2, §14); D-B1-09 revised to native prepared statements with PgBouncer `max_prepared_statements`; optional signed tenant context proposed (D-B1-24) |
| **R-18** | v6 (Codex P1) | a tenant admin inviting a never-registered email: `ensure_identity` created only `auth.identities`; `eora_app` cannot INSERT `users`, and `memberships.user_id` references `users` → the `INVITED` membership insert fails | identity **and** profile are created together inside the definer (`invite_identity` / `provision_identity`); `eora_auth_definer` gets an INSERT-only column grant + INSERT policy on `users` (§2.4, §5.3) |
| **R-19** | v6 (Codex P1) | `record_security_event` runs as `eora_auth_definer`, which had no INSERT path into forced-RLS `security_events` → auth events lost | INSERT-only column grant + `FOR INSERT TO eora_auth_definer WITH CHECK (source IN ('auth_web','auth_delivery'))` policy; no SELECT/UPDATE/DELETE; tenant id is never a caller parameter (§8) |
| **R-20** | v6 (Codex P2) | caller-chosen `candidate_id` in `ensure_identity` let a caller detect whether an email already existed (account enumeration); UUIDv7 creation time would leak it too | function split into `invite_identity(email)` (web) and `provision_identity(email)` (provisioner); the id is generated inside the definer as a **random UUIDv4** (user ids only — D-B1-10 amendment) so the returned id carries no creation-time signal |
| **R-21** | v6 (Codex P2) | `claim_delivery` had no lease: a worker crash after claim, after issuance or after sending stranded the delivery (incl. the first admin's invitation) | claim lease with expiry, atomic reclaim with secret rotation, claim token checked by `mark_delivery_result`, bounded retries → `DEAD` + alert + resend path (§2.9) |
| **R-22** | v6 (owner consistency check A) | D-B1-24 policies called `auth_api.ctx_tenant()` although `eora_app` has no privilege on `auth_api` → every query would fail | signed context moved to separate schemas `ctx` (key table) and `ctx_api` (verification functions) owned by NOLOGIN `eora_ctx_verifier`; `EXECUTE` for runtime roles only; `auth` stays closed (§5.5) |
| **R-23** | v6 (owner consistency check B) | `ensure_identity` had two grantees, contradicting "one grantee per function" | split into two functions (R-20); the rule stays absolute and is enforced by the schema guard |
| **R-12** | v4 (Codex P1-04) | denied-access audits were written in the request transaction, which is rolled back on every non-2xx → `access.not_found` lost, T-ISO-01 impossible | security events are buffered in memory and flushed **after** commit/rollback in an independent short transaction to an append-only `security_events` table, with log fallback (§8) |

## 1. Tenant, company and plant data model
- `tenants` (platform root, no `tenant_id` column): `id uuid`, `code` unique, `name`, `status` (`PROVISIONING|ACTIVE|SUSPENDED|OFFBOARDING`), `timezone` (default `Asia/Jakarta`, D-044), `data_region`, timestamps.
- `companies`, `plants` (composite FK to company), business calendars (`business_calendars` with `plant_id` NULL = tenant default, `calendar_exceptions`, `shift_calendars`, `shifts`; ADR-0012).
- Warehouses, locations, work centers are **B2**; B1 scope types `TENANT`, `COMPANY`, `PLANT` (D-B1-02).
- Every tenant-owned table: PK `id uuid` (UUIDv7, D-B1-10; **exception:** global user ids are random UUIDv4, R-20), `tenant_id uuid NOT NULL`, `UNIQUE (tenant_id, id)`, composite FKs only, audit columns + `version`, `tenant_id`-leading indexes, RLS **enabled and forced** in its creating migration.

## 2. Identity, credentials, invitation, reset, provisioning
### 2.1 Privilege domains (the boundary that contains SQL injection)
| Domain | DB role (connection) | Can touch | Cannot touch | Used by |
|---|---|---|---|---|
| Business | `eora_app` (`pgsql`) | public tenant tables under forced RLS; `users` profile per policy; `security_events` INSERT | **anything in `auth` / `auth_api`** | all business HTTP routes, workers |
| Web authentication | `eora_auth_web` (`pgsql_auth`) | `EXECUTE` on `auth_api.login_lookup`, `record_login_failure`, `request_password_reset`, `consume_password_reset`, `consume_invitation`, `invite_identity`, `record_security_event` (§2.8) | business tables; token issuance; any `auth` table directly | only `App\Platform\Auth` code on `/auth/*` routes and the invite command handler's identity step (arch test) |
| Secret delivery | `eora_auth_delivery` (`pgsql_delivery`) | `EXECUTE` on `auth_api.claim_delivery`, `enqueue_invitation`, `issue_invitation_secret(delivery_id, claim_token)`, `issue_reset_secret(delivery_id, claim_token)`, `mark_delivery_result` (§2.8, §2.9) | consumption functions; business tables; any `auth` table directly | dedicated delivery worker (separate queue + process, own credentials) |
| Credential storage | `eora_auth_definer` (NOLOGIN) | owns `auth` schema tables and the `SECURITY DEFINER` functions; **read-only** column grant on `memberships (tenant_id, id, user_id, status, invited_at)` with a SELECT policy `TO eora_auth_definer` (validate invitations, cleanup); **INSERT-only** column grant on `users (id, display_name, email_display, locale)` with `FOR INSERT TO eora_auth_definer WITH CHECK (true)` (profile creation, R-18); **INSERT-only** column grant on `security_events` with the policy of §8 (R-19) | everything else in `public`; no SELECT/UPDATE/DELETE on `users` or `security_events` | — |
| Provisioning | `eora_provisioner` | member of `eora_app` + tenant insert + `auth_api.provision_identity` | token issuance/consumption | provisioning command only |
A SQL injection in a business route runs as `eora_app` and **cannot reach the auth domain at all** — neither tables nor functions. A SQL injection in an auth route (small, reviewed, fixed-statement surface) can call only the web functions listed, none of which returns a usable secret. The delivery worker can issue secrets but cannot consume them and runs in its own process with its own credentials.

### 2.2 Global identity (D-B1-03, final)
| Object | Content | Access |
|---|---|---|
| `auth.identities` | `user_id`, `email_normalized` (citext unique), `status` (`ACTIVE|DISABLED`), `password_hash` (nullable until invitation accepted), `password_changed_at`, `lockout_cleared_at`, `mfa_secret_enc`, `recovery_codes_hash` | definer functions only |
| `auth.login_failures` | `user_id`, `occurred_at`, `ip_hash` (append-only) | definer functions only |
| `public.users` (profile) | `id` (= `user_id`), `display_name`, `locale`, `email_display` (copy kept in sync **only** by definer functions), timestamps — no status, no secret | `eora_app`: forced RLS `id = ctx_user OR id IN (SELECT user_id FROM memberships)`; column grants `SELECT (id, display_name, locale, email_display)`, `UPDATE (display_name, locale)`; no INSERT/DELETE |
**Login (`POST /auth/login`, no context needed):** `auth_api.login_lookup(email text) RETURNS TABLE(user_id uuid, password_hash text, status text, locked_until timestamptz)` — `SECURITY DEFINER`, owner `eora_auth_definer`, `SET search_path = ''`, every reference schema-qualified (`auth.identities`, `pg_catalog.lower`), exact match on normalized email, `STABLE`, at most one row, no profile, no tenant data. **Locked** identities (failures in the window ≥ threshold, counted after `lockout_cleared_at`) and DISABLED identities are returned with status only and `password_hash = NULL`. The app (via `pgsql_auth`) verifies the hash with argon2id in PHP; if no row/hash, it verifies against a fixed dummy hash (constant work) and returns the same `401 UNAUTHENTICATED` body. On a failed verification it calls `record_login_failure(user_id, ip_hash)` (append-only). There is **no** "login succeeded" function: a successful verification changes nothing in `auth` (last login is a `security_events` row), so no caller can forge success or lower a lockout. Then session regeneration, and the tenant picker runs on `eora_app` with `ctx_user` only.
**Residual risk (accepted, documented):** a compromised auth route could call `login_lookup` for a *known* email and obtain that one hash for offline cracking. Mitigations: argon2id parameters (D-B1-07), no wildcard/listing, no dynamic SQL in auth code (arch test), MFA for privileged roles (D-B1-04), DB-side lockout that callers can raise but never lower (R-14). Alternative removing even this residual (DB-side bcrypt verification via `pgcrypto`, no hash ever returned) is evaluated in D-B1-23b.

### 2.3 Password reset (fix for R-11)
1. `POST /auth/password/forgot` → `auth_api.request_password_reset(email)` (web role): creates `auth.reset_requests` (`id`, `user_id` or NULL when unknown email, `status REQUESTED`, `requested_at`, `ip_hash`), enforces per-identity request throttling **inside the function** (e.g. ≤ 3 open requests/hour), returns **nothing**; the HTTP answer is identical for known and unknown emails. Hand-off to delivery (no `NOTIFY`, pooler-safe): the function inserts `auth.delivery_queue (kind RESET, request_id)`.
2. Delivery worker (`eora_auth_delivery`) calls `auth_api.claim_delivery()` (`FOR UPDATE SKIP LOCKED` inside the definer; returns `delivery_id`, kind, and the **recipient address taken from `auth.identities`** — never from the caller), then `issue_reset_secret(delivery_id)`, which re-validates (§2.8) and → generates 256-bit random secret, stores **SHA-256 hash** + `expires_at`, returns the raw secret to the worker only, commits; then sends the email **outside** any transaction; then `mark_delivery_result(request_id, ok)`. On send failure: retry with backoff; each retry **rotates** the secret (old hash invalidated) so at most one secret is valid.
3. `POST /auth/password/reset` → `auth_api.consume_password_reset(secret text, new_hash text)` (web role): hashes the secret, `UPDATE … SET status = 'CONSUMED' WHERE hash = $1 AND status = 'SENT' AND expires_at > now() RETURNING user_id` (atomic single use), sets the new password hash, sets `lockout_cleared_at = now()` (email ownership proven), invalidates every other open reset secret of that identity, bumps `password_changed_at` (all sessions of the user invalidated by the app on next request), records the event. Returns only success/failure (uniform failure for invalid, expired, replayed).
4. Privileged accounts: when MFA is enrolled (B1.1), a reset does **not** remove MFA; the next login still requires the second factor. Without MFA (B1), a reset of an account holding privileged roles also notifies all tenant administrators of that tenant (post-commit, delivery worker) — D-B1-18.
Neither `eora_app` nor `eora_auth_web` can ever read a raw secret; the delivery role can read a raw secret only at issuance and cannot consume it.

### 2.4 Invitations and first administrator (fix for R-10)
- Provisioning or a tenant admin invites a user: in the tenant transaction (`eora_app`/`eora_provisioner`) → membership `INVITED` + outbox event `membership.invited (tenant_id, membership_id, user_id)`; the identity is resolved beforehand on the auth connection: `auth_api.invite_identity(email)` (`eora_auth_web`, tenant admin's invite) or `auth_api.provision_identity(email)` (`eora_provisioner`). In **one definer transaction** the function looks up the normalized email; if absent it generates a random UUIDv4, inserts `auth.identities` (no password, status ACTIVE) **and** the `public.users` profile (`display_name` = local part of the email until the user sets it, `email_display`), then returns the `user_id`. Existing email → the existing id, no change. Because the id is server-generated and random, and both branches return one id, the caller cannot tell new from existing (R-20); identity and profile can never exist without each other. Then the tenant transaction inserts the membership with that `user_id` under forced RLS.
  - **Orphans cannot gain access:** an identity without a membership has no password (it is set only by consuming an invitation secret, which is bound to a membership) and no tenant to enter; the cleanup job removes identities with neither membership nor password after 7 days (definer reads `memberships` via its read-only grant).
  - **Abuse impact** (SQL injection in an auth route): password-less identities without memberships (cleaned up); no disclosure of existence.
- After commit, the outbox relay (`eora_app` worker, no auth privilege) enqueues a job carrying only `membership_id`; the delivery worker calls `auth_api.enqueue_invitation(membership_id)` → the definer reads `memberships` (read-only grant) and records `auth.delivery_queue (kind INVITATION, membership_id, tenant_id, user_id)` **only if** the membership exists, is `INVITED`, was invited ≤ 30 days ago, its identity is not DISABLED and the per-membership cap (≤ 5 sends/24 h) holds; then `issue_invitation_secret(delivery_id)` → stores hash + `expires_at` (recommended 72 h), returns the raw secret to the worker only → sends the invitation link **outside** any transaction → `mark_delivery_result`. Failure → retry with rotation; after N failures the invitation is `DELIVERY_FAILED` and visible to the inviter, who can resend.
- Acceptance (`POST /auth/invitations/accept`, web role): `auth_api.consume_invitation(secret, password_hash)` → atomic single use (`SENT` + not expired → `ACCEPTED`), sets the password hash if none, returns `(user_id, tenant_id, membership_id)` to the acceptance request only. The app then opens a tenant transaction on `eora_app` with that context and sets the membership `ACTIVE` under RLS (retried once). If activation still fails, an `invitation.activation_failed` security event and an inbox notification reach the inviting admin, who re-activates or re-invites; there is **no** function that lets a web caller list another user's pending memberships (R-16).
- Membership activation policy (accept → ACTIVE immediately vs. admin confirmation) is D-B1-19; recommendation: ACTIVE on acceptance for invitations created by an admin.
- Operator fallback for the very first tenant administrator when no mail transport exists yet (dev/pilot setup): `eora:tenant:provision --deliver=console` makes the **delivery worker logic** print the one-time link to the operator's terminal (never to logs), audited as `invitation.delivered_console` — D-B1-18.

### 2.5 Tenant provisioning without bypassing RLS (corrected)
Order (three independent steps, each idempotent; no external I/O inside a transaction):
1. **Identity** (`pgsql_provisioner` → `auth_api.provision_identity(email)`): creates identity + profile atomically when new; returns the `user_id` (new or existing).
2. **Tenant transaction** (`eora_provisioner`, forced RLS): `INSERT tenants (PROVISIONING)` → `set_config('app.tenant_id', new_id, true)` + system actor → `companies`, `plants`, default `business_calendars`, bootstrap roles (D-B1-16), `memberships (INVITED)`, `role_assignments (TENANT_ADMIN, tenant scope)`, `audit_events (tenant.provisioned)`, outbox `membership.invited` → `UPDATE tenants SET status = 'ACTIVE'` → COMMIT. Any error → full rollback (tenant, company, plant, membership, assignment all absent).
3. **Delivery** (after commit): §2.4.
Re-running with the same tenant code: step 2 detects the existing tenant and stops; step 3 can be re-triggered as "resend". An identity created in step 1 for a run whose step 2 then failed has no membership — it cannot log in to any tenant; a cleanup job removes identities without memberships and without credentials after 7 days (D-B1-18).

### 2.6 Memberships and tenants (unchanged from v3)
| Table | RLS | Policies for `eora_app` |
|---|---|---|
| `memberships` | enable + force | P1 all: `tenant_id = ctx_tenant`; P2 SELECT: `ctx_tenant IS NULL AND user_id = ctx_user` |
| `tenants` | enable + force | SELECT: `id = ctx_tenant` or (`ctx_tenant IS NULL` and member via `memberships`); INSERT/status UPDATE only `TO eora_provisioner` |
`ctx_tenant` / `ctx_user` = `(SELECT NULLIF(current_setting('app.tenant_id' | 'app.user_id', true), '')::uuid)`.

### 2.7 Definer-function rules
Owner `eora_auth_definer` (NOLOGIN, owns only `auth` objects); `SECURITY DEFINER` + `SET search_path = ''`; all identifiers schema-qualified incl. `pg_catalog`; `REVOKE ALL ON FUNCTION … FROM PUBLIC` then `GRANT EXECUTE` to exactly one role each (table in §2.1); no dynamic SQL; inputs length-checked; functions return no more than documented; every function covered by abuse-case tests (§12); allowlist enforced by the schema guard (§5.4).

### 2.8 Auth function catalogue — authorization rules (final gate)
Global rule: **no `auth_api` function treats `app.user_id`, `app.tenant_id` or any caller-supplied user/tenant id as proof of identity or ownership.** Proof is one of: knowledge of the account password (verified by the app, outcome cannot lower lockout), possession of a one-time secret delivered to the account's address, or a record created earlier by an authorized path and re-validated at use.
| Function | Grantee | Input | Validation inside the function | Returns | Abuse impact if called directly |
|---|---|---|---|---|---|
| `login_lookup(email)` | `eora_auth_web` | email (≤ 254 chars) | exact normalized match; lockout/disabled → no hash | `user_id, password_hash | NULL, status` | hash of one *known*, non-locked email (residual, §2.2) |
| `record_login_failure(user_id, ip_hash)` | `eora_auth_web` | id | identity exists | — | can only **add** failures (lock a victim = same as HTTP brute force; rate-capped per IP hash in the function) |
| `request_password_reset(email)` | `eora_auth_web` | email | ≤ 3 open requests/identity/hour; unknown email → no row | nothing | reset email goes to the **real owner**; spam bounded by cap |
| `consume_password_reset(secret, new_hash)` | `eora_auth_web` | secret, argon2id hash (format-checked) | secret hash matches a `SENT`, unexpired, single-use row | success flag | needs the delivered secret |
| `consume_invitation(secret, new_hash)` | `eora_auth_web` | secret, hash | `SENT`, unexpired, single-use; sets password only if none | `user_id, tenant_id, membership_id` | needs the delivered secret |
| `invite_identity(email)` | `eora_auth_web` only | email | normalized exact match; when new: random UUIDv4 + identity + profile in one transaction; no password, no membership; platform-wide cap on newly created identities per hour (DB counter; above it the function refuses and writes `identity.creation_capped`) — the DB cannot tell inviters apart, so per-inviter limits stay at the HTTP layer | `user_id` | password-less identities without membership (cleanup job); no existence disclosure |
| `provision_identity(email)` | `eora_provisioner` only | email | same as `invite_identity` | `user_id` | as above |
| `record_security_event(action, detail_code, email_hmac, ip_hash, ua_hash, correlation_id)` | `eora_auth_web` | validated enum/fields — **no tenant/user id parameter** | field allowlist, length limits, writes `tenant_id = NULL`, `source = 'auth_web'`; tenant-scoped auth events (invitation accepted, reset consumed) are written **inside** the consuming function from stored rows, never from caller input | — | noise events (rate-capped), cannot alter or read events |
| `claim_delivery(worker_id)` | `eora_auth_delivery` | worker label | claims one `PENDING` (due) or `CLAIMED`-with-expired-lease row, atomically (§2.9) | `delivery_id, claim_token, kind, recipient` (from identity) | none beyond doing the worker's job |
| `enqueue_invitation(membership_id)` | `eora_auth_delivery` | membership id | membership exists, `INVITED`, ≤ 30 days old, identity not DISABLED, ≤ 5 sends/24 h | `delivery_id` or nothing | resend to the **legitimate invitee** only; capped |
| `issue_invitation_secret(delivery_id, claim_token)` | `eora_auth_delivery` | delivery id + current claim token | delivery row claimed by this token and lease not expired, kind INVITATION, membership still `INVITED` and matches stored tenant/user, identity not DISABLED; rotates any previous secret | raw secret (once) | secret only for a real invitation; sent to the invitee's own address |
| `issue_reset_secret(delivery_id, claim_token)` | `eora_auth_delivery` | delivery id + current claim token | delivery row claimed by this token and lease not expired, kind RESET, reset request `REQUESTED` and ≤ 15 min old, identity ACTIVE; rotates previous secret | raw secret (once) | secret only for a recorded request; sent to the owner's address |
| `mark_delivery_result(delivery_id, claim_token, ok, error_code)` | `eora_auth_delivery` | id, token, enum | claim token must match the **current** claim (a stale worker cannot overwrite a reclaimed delivery) | — | can mark a delivery failed (resend possible) |
Delivery-worker compromise (credentials + process): attacker can mint secrets for genuinely pending requests and read them before sending — mitigated by separate process/credentials (D-B1-18), short TTLs, per-target caps, `security_events` for every issuance, and alerting on issuance without a matching send.

### 2.9 Delivery queue: lease, recovery, retries (R-21)
`auth.delivery_queue`: `id`, `kind` (`INVITATION|RESET|PRIVILEGED_RESET_NOTICE`), target refs, `status` (`PENDING → CLAIMED → SENT` | `FAILED → PENDING` | `DEAD` | `CANCELLED`), `claim_token` (random per claim), `claimed_by`, `claim_expires_at`, `attempts`, `max_attempts`, `next_attempt_at`, `secret_generation`, `last_error_code`, timestamps.
- **Claim (atomic):** `UPDATE … SET status = 'CLAIMED', claim_token = gen_random_uuid(), claimed_by = $worker, claim_expires_at = now() + lease, attempts = attempts + 1 WHERE id = (SELECT id FROM auth.delivery_queue WHERE (status = 'PENDING' AND next_attempt_at <= now()) OR (status = 'CLAIMED' AND claim_expires_at < now()) ORDER BY next_attempt_at FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING …`. A row whose `attempts` already reached `max_attempts` becomes `DEAD` instead of being claimed.
- **Lease:** recommended 2 min (longer than one send attempt incl. provider timeout); expired claims are reclaimable by any worker — no manual unlock needed.
- **Secret rotation:** every `issue_*_secret` call (first claim or reclaim) generates a new secret, increments `secret_generation` and invalidates the previous hash — at most one secret per delivery is ever valid.
- **Stale worker:** `issue_*_secret` and `mark_delivery_result` require the current `claim_token`; a worker that resumes after its lease expired gets a rejection and must not send (it checks the issuance result before sending).
- **Duplicate delivery:** if a worker sent the message but crashed before `mark_delivery_result`, the reclaim sends a new message with a new secret; the recipient may receive two messages, only the newest works; this is documented user-facing behaviour (message text says "only the latest link is valid").
- **Bounded retries:** `max_attempts` 5 with exponential backoff (recommended 1, 5, 15, 60 min); then `DEAD`, a `security_events` row (`delivery.dead`) and an alert; the inviting admin sees `DELIVERY_FAILED` and can resend (new delivery row, attempts reset).
- **First administrator:** provisioning prints the delivery status; if `DEAD` or no mail transport, the operator runs `eora:tenant:resend-invitation --deliver=console` (new delivery row, console channel, audited) — the invitation can never stay stranded silently.

## 3. Role and permission architecture
- Global: `permissions`, `role_templates`, `role_template_permissions` — template content is O-007 (OPEN); only the bootstrap administrator role is needed for provisioning (D-B1-16).
- Tenant: `roles`, `role_permissions`, `role_assignments` (scope columns + CHECK + composite FKs, `valid_from/valid_until`, `granted_by`, `grant_reason`).
- Evaluation (doc 18 §5); responses `403 FORBIDDEN`, `403 SCOPE_FORBIDDEN`, `404 NOT_FOUND` for foreign/out-of-scope ids. No `Gate::before` shortcut; Platform Super Admin has no tenant path (O-007).

## 4. Session, CSRF and cookie security (ADR-0006)
Password **change** for a signed-in user in B1 = the same email-confirmed reset flow (no `set_own_password`, R-13; D-B1-23). Sanctum stateful SPA; `__Host-` session cookie (`Secure`, `HttpOnly`, `Path=/`, no `Domain`, `SameSite=Lax`); `XSRF-TOKEN`; CSRF + `Origin` check on non-GET; session regenerated on login/tenant/plant/privilege change; logout invalidates server session; password change or reset invalidates all sessions of the user (session records carry `password_changed_at` checked per request); lifetimes D-B1-05. Endpoints: `/auth/login`, `/auth/logout`, `/auth/me`, `/auth/tenant`, `/auth/plant`, `/auth/password/forgot`, `/auth/password/reset`, `/auth/invitations/accept`.

## 5. PostgreSQL roles and RLS
### 5.1 Roles (D-B1-01)
| Role | Login | Owns | Notes |
|---|---|---|---|
| bootstrap superuser | yes | — | creates roles/DBs/extensions only (script in dev/CI, infra in prod) |
| `eora_owner` | yes | public schema objects | migrations, group A tests |
| `eora_auth_definer` | **no** | `auth` schema + `auth_api` functions | owner only |
| `eora_app` | yes | nothing | NOSUPERUSER, NOBYPASSRLS; no `auth`/`auth_api` privilege |
| `eora_auth_web` | yes | nothing | EXECUTE on web functions only; no table privilege |
| `eora_auth_delivery` | yes | nothing | EXECUTE on delivery functions only; separate process |
| `eora_provisioner` | yes | nothing | member of `eora_app` + tenant insert + `provision_identity`; NOBYPASSRLS |
| `eora_ctx_verifier` | **no** | schemas `ctx` (key table) and `ctx_api` (verification functions) | owner only — **PoC scope, D-B1-24** |
No role is a member of `eora_owner` or `eora_auth_definer`; `REVOKE ALL ON SCHEMA public, auth, auth_api FROM PUBLIC`.

### 5.2 Tenant-owned tables
```sql
ALTER TABLE plants ENABLE ROW LEVEL SECURITY;
ALTER TABLE plants FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON plants
  USING      (tenant_id = (SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid))
  WITH CHECK (tenant_id = (SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid));
```

**Threat-model note (R-17):** `app.tenant_id` / `app.user_id` are ordinary custom settings — any session can set them. Context-based RLS therefore contains **application bugs** (a missing filter, a wrong join, an unscoped job), not an attacker who can run arbitrary SQL. SQL-injection defence is: parameterized statements only (native prepared statements, D-B1-09), static/arch checks banning raw SQL with input, least-privilege roles (§2.1) so injected SQL cannot reach credentials, and the optional signed context of D-B1-24.

### 5.5 Signed tenant context — PoC design (D-B1-24: PREFERRED FOR PoC, IMPLEMENTATION PENDING APPROVAL)
Nothing in this section is approved for implementation or production. If the PoC fails, **no automatic fallback** is introduced: the outcome is reported and the owner decides (Option A stays the documented baseline only by explicit decision).
| Aspect | Design |
|---|---|
| Threat model | Attacker executes SQL as `eora_app` inside a request/job transaction (SQL injection) or the app has a scoping bug. Option A (GUC + RLS) contains bugs only; Option B prevents forging another tenant's context. Out of scope: compromise of the PHP process or its secrets (it holds the key), DB superuser/owner compromise. |
| Trust boundary | PHP runtime (web + workers) **signs**; PostgreSQL **verifies**; injected SQL can read the current token but has no key. |
| Schemas & roles | `ctx` (table `ctx.keys(kid, key bytea, not_after)`) and `ctx_api` (functions) owned by NOLOGIN `eora_ctx_verifier`; `REVOKE ALL ON SCHEMA ctx FROM PUBLIC` (no login role has `USAGE`); `ctx_api`: `USAGE` + `EXECUTE` only for `eora_app` (inherited by `eora_provisioner`); auth roles need none. `auth` / `auth_api` untouched (R-22). |
| Key ownership | 256-bit HMAC key from the secrets manager (D-B1-22), injected into app/worker env; the same key loaded into `ctx.keys` by the deployment job (owner credentials); `kid` in every token; two keys valid during rotation; rotation = add new kid → deploy app signing with it → remove old kid after max token lifetime. |
| Transaction binding | After `BEGIN`, the app reads `pg_backend_pid()` and `transaction_timestamp()` (one round-trip). Token payload: `kid, tenant_id, user_id, pid, xact_ts, exp`; signature `HMAC-SHA256(key, payload)`; set via bound parameter `set_config('app.ctx', $1, true)`. |
| Verification | `ctx_api.tenant_id()` / `ctx_api.user_id()`: `SECURITY DEFINER`, `STABLE`, `PARALLEL SAFE` where allowed, no arguments, `SET search_path = ''`, schema-qualified `pgcrypto` calls; checks kid known and not expired, HMAC equal, `pid = pg_backend_pid()`, `xact_ts = transaction_timestamp()`, `exp > now()`; any failure → NULL. |
| Fail-closed | Policies `tenant_id = (SELECT ctx_api.tenant_id())`: NULL → no rows, inserts violate WITH CHECK. A bad token never widens access. |
| Replay & expiry | Token valid only in the same backend **and** the same transaction (pid + start timestamp); `exp` ≤ maximum request/job duration (D-B1-11). Reuse in a later transaction or another connection → NULL. |
| Tenant switching | Context is rebuilt from the server session for every transaction; switching tenant regenerates the session (§4); no token outlives its transaction. |
| Queue workers | Job middleware signs with the job's tenant/actor; workers hold the key; context flushed per job (§6.3). |
| PgBouncer | Token is transaction-local and the pid is stable within a transaction in transaction-pooling mode; a later transaction on another server connection carries no token. |
| SQL-injection limits | Injected SQL can still read/write the **current** tenant's data (inherent); it can read the current token (no gain); it must not see other sessions' tokens: bound parameters keep tokens out of `pg_stat_activity.query` text (native prepares, D-B1-09); `log_parameter_max_length = 0` for the runtime roles. |
| Performance | +1 round-trip per transaction; one HMAC per statement (initplan evaluated once per statement). PoC target: p95 overhead < 2 % on W-01..W-03 (doc 20). |
| Rollback | Policies reference only `ctx_api.tenant_id()`; replacing its body is a single migration — **executed only by explicit owner decision**, never automatically. |
**Mandatory verification plan PoC-CTX-01..13** (not to be run before separate approval):
| ID | Verification | Pass criterion |
|---|---|---|
| PoC-CTX-01 | forged/random token via `set_config('app.ctx', …, true)` | 0 rows; inserts rejected |
| PoC-CTX-02 | own valid token reused in a later transaction on the same connection | NULL → 0 rows |
| PoC-CTX-03 | token visible to another session of the same role (`pg_stat_activity`, logs, `pg_stat_statements`) | not visible anywhere |
| PoC-CTX-04 | runtime roles read `ctx.keys` or call any `ctx`/`ctx_api` object other than the two verifiers | permission denied |
| PoC-CTX-05 | verifier functions accept no arguments; catalogue check of owner, `search_path`, grants | pass |
| PoC-CTX-06 | unset, empty, malformed, expired token, unknown kid | 0 rows, no error leak |
| PoC-CTX-07 | key rotation with two kids active; old kid removed after max lifetime | continuous service; old tokens rejected after removal |
| PoC-CTX-08 | interleaved tenant A/B transactions through PgBouncer from parallel workers | zero cross reads |
| PoC-CTX-09 | worker processes job B then job A | A never sees B |
| PoC-CTX-10 | `EXPLAIN (ANALYZE)` of representative queries | verifier evaluated once per statement (initplan) |
| PoC-CTX-11 | benchmark W-01..W-03 vs Option A baseline | p95 overhead < 2 % (else report, no automatic change) |
| PoC-CTX-12 | rollback migration rehearsal in a throw-away database | isolation suite green after rollback; rehearsal only |
| PoC-CTX-13 | Laravel PDO native prepares through PgBouncer ≥ 1.21 (`max_prepared_statements`) | all suites green, no prepared-statement errors |

### 5.3 Platform tables
`tenants`, `memberships` §2.6; `users` §2.2; `permissions`, `role_templates*` SELECT; `security_events` §8; `auth.*` definer-only.

### 5.4 Schema guard (group A)
Fails if: a `tenant_id` table lacks forced RLS + policy; any LOGIN role owns objects or has `rolsuper`/`rolbypassrls`; a `SECURITY DEFINER` function is off the allowlist, lacks `search_path=''`, is owned by anyone but `eora_auth_definer` (auth) or `eora_ctx_verifier` (ctx, PoC), or is executable by `PUBLIC` or by a role other than the one listed in §2.1; `eora_app` has any privilege on `auth`/`auth_api`; `security_events`/`audit_events` updatable or deletable by a runtime role; grants differ from the approved matrix.

## 6. Request lifecycle and TenantTransaction
### 6.1 Middleware order
1 `AssignCorrelationId` → 2 cookies/`StartSession` (Redis) → 3 `PreventRequestForgery` → 4 `throttle` (Redis) → 5 **`TenantTransaction`** (+ security-event flush in its `finally`, §8) → 6 `auth:sanctum` → 7 `EnsureActiveMembership` → 8 `SubstituteBindings` (inside the transaction via middleware priority) → 9 controller → response built → COMMIT/ROLLBACK → flush security events. Steps 1–4 never touch PostgreSQL. `/auth/*` routes use the auth connection instead of steps 5–8 (except picker/me on `eora_app`).

### 6.2 TenantTransaction contract
- Context from the **server session** (user id, active tenant/plant); `BEGIN` → `set_config('app.user_id'|'app.tenant_id', …, true)` → `SET LOCAL statement_timeout/lock_timeout` → next → COMMIT on 2xx/3xx, ROLLBACK otherwise → security-event flush (independent transaction).
- Exceptions inside the pipeline are converted to problem+json **inside** step 5 (Laravel converts per pipeline stage); the renderer **only buffers** security events in memory (no DB call), TenantTransaction then rolls back and flushes.
- GET → `READ ONLY`; sensitive-read audits via the post-commit flush path (D-B1-17 merged into §8).
- Nested: only TenantTransaction/job middleware opens the outer transaction; inner `DB::transaction()` = savepoint; arch test bans `beginTransaction/commit/rollBack` outside `App\Platform`; retry (`40001`, `40P01`) only at the outer level for idempotent commands.
- No external I/O inside (HTTP, mail, storage writes, PDF, push); outbox + `afterCommit` jobs; downloads via signed URLs on routes outside TenantTransaction after a short authorization transaction; no streamed responses on transactional routes.

### 6.3 Queue workers and pooling
`TenantJobMiddleware` asserts `DB::transactionLevel() === 0` (else rollback + reconnect + report), opens the transaction with the job's context, `finally` rollback; `TenantContext` is `scoped` (flushed between jobs). PgBouncer transaction mode; banned: `set_config(…, false)`, plain `SET app.*`, session advisory locks, `LISTEN`, temp tables, `DB::unprepared` outside migrations; prepared statements per D-B1-09 (native server-side prepares through PgBouncer `max_prepared_statements`; no emulated prepares). Auth and delivery connections use the same rules (no session state).

## 7. Connection pooling
Pooler in Compose + CI docker job; pool sizing from cores (`(cores × 2) + 1` start, measured in doc 20); migrations, provisioning and the delivery worker may connect directly.

## 8. Audit and durable security events (fix for R-12)
- **Business audit** (`audit_events`, tenant, append-only): written **inside** the command transaction — it must disappear when the business change rolls back.
- **Security events** (`security_events`, append-only): `id`, `occurred_at`, `tenant_id` (nullable), `user_id` (nullable), `membership_id`, `action` (`access.not_found`, `access.forbidden`, `access.scope_forbidden`, `auth.failed`, `auth.locked`, `auth.password_reset_requested`, `auth.password_reset_consumed`, `invitation.accepted`, `export.downloaded`, …), `resource`, `requested_id` (≤ 64 chars, validated charset), `route`, `http_status`, `correlation_id`, `ip_hash`, `user_agent_hash`, `email_hmac` (for auth events; HMAC with a server pepper, never the email), `detail_code`. **Never** request bodies, passwords, tokens, secrets, cookies or other tenants' data.
- **Path:** events are collected in a request-scoped in-memory buffer (renderer, policies, auth code) → after TenantTransaction commits **or rolls back**, a separate short transaction on `eora_app` sets the same context and inserts the buffered rows. Policy: forced RLS; `INSERT WITH CHECK (tenant_id IS NOT DISTINCT FROM ctx_tenant)`; `SELECT USING (tenant_id = ctx_tenant)` (tenant admins/auditors with `core.audit`); no UPDATE/DELETE grant + trigger. Auth events are written by `eora_auth_definer` functions (R-19): INSERT-only column grant on `security_events` + policy `FOR INSERT TO eora_auth_definer WITH CHECK (source IN ('auth_web','auth_delivery'))`; no SELECT/UPDATE/DELETE grant for the definer; caller-facing `record_security_event` never accepts a tenant or user id (it writes `tenant_id = NULL`); tenant-scoped auth events are produced only inside consuming functions from stored rows. Business-path events use `source = 'app'` and the `eora_app` policy above.
- **Failure handling:** the event is first written synchronously to the JSON log (correlation id, no secrets); if the DB flush fails, the response is unchanged, a `security_event_flush_failed` metric increments and a retry job is queued with the event payload (Redis, `tenant_id` included); the business transaction is never reopened or altered. Residual: a process crash between response and flush loses the DB row but not the log line.
- `access.not_found` is recorded for every 404 on an id-addressed route (the app cannot distinguish "other tenant" from "absent"); probing shows up as a pattern (T-ISO-01).
- Retention/partitioning D-B1-12.

## 9. Migration dependency order (B1 only)
| # | Object | Depends on | RLS |
|---|---|---|---|
| 0 | roles (§5.1), databases, schemas `auth`/`auth_api`, base privileges (D-B1-01) | cluster | — |
| 1 | `tenants` | 0 | forced |
| 2 | `auth.identities`, `auth.reset_requests`, `auth.invitations`, `auth.delivery_queue` + `auth_api` functions | 0 | definer-only |
| 3 | `users` profile | 2 | forced |
| 4 | `permissions`, `role_templates`, `role_template_permissions` | 0 | read-only |
| 5 | `companies` → 6 `plants` | 1 | forced |
| 7 | `memberships` | 1, 3, 6 | forced + picker |
| 8 | `roles`, `role_permissions` | 1, 4 | forced |
| 9 | `role_assignments` | 5, 6, 7, 8 | forced |
| 10 | business calendars | 6 | forced |
| 11 | `audit_events` + trigger | 1 | forced |
| 12 | `security_events` + trigger | 1 | forced (insert/select policies §8) |
| 13 | outbox (minimal, for `membership.invited`) | 1 | forced |
| 14 | grants matrix + schema guard | all | — |

```mermaid
erDiagram
  TENANTS ||--o{ COMPANIES : "tenant_id"
  COMPANIES ||--o{ PLANTS : "(tenant_id, company_id)"
  TENANTS ||--o{ MEMBERSHIPS : "tenant_id"
  USERS ||--o{ MEMBERSHIPS : "user_id"
  AUTH_IDENTITIES ||--|| USERS : "user_id (auth schema, definer-only)"
  AUTH_IDENTITIES ||--o{ AUTH_RESET_REQUESTS : "user_id"
  MEMBERSHIPS ||--o{ AUTH_INVITATIONS : "membership_id"
  PLANTS ||--o{ MEMBERSHIPS : "default_plant_id"
  TENANTS ||--o{ ROLES : "tenant_id"
  ROLES ||--o{ ROLE_PERMISSIONS : "(tenant_id, role_id)"
  PERMISSIONS ||--o{ ROLE_PERMISSIONS : "permission_code"
  MEMBERSHIPS ||--o{ ROLE_ASSIGNMENTS : "(tenant_id, membership_id)"
  ROLES ||--o{ ROLE_ASSIGNMENTS : "(tenant_id, role_id)"
  PLANTS ||--o{ ROLE_ASSIGNMENTS : "scope plant_id"
  PLANTS ||--o{ BUSINESS_CALENDARS : "plant_id (NULL = tenant default)"
  TENANTS ||--o{ AUDIT_EVENTS : "tenant_id"
  TENANTS ||--o{ SECURITY_EVENTS : "tenant_id (nullable)"
```

## 10. Rollback plan and destructive-command hardening
Every migration reversible; group A runs migrate → rollback → migrate as `eora_owner` (+ the definer objects via the bootstrap script). No production deployment in B1 → forward-fix only after the first pilot tenant. RLS never disabled as rollback. Hardening (B1 code dependencies): `TestDatabaseGuard` host allowlist (`EORA_TEST_DB_HOST_ALLOW` override); `DB::prohibitDestructiveCommands($app->isProduction())`; runtime roles cannot DROP/TRUNCATE; owner credentials only in the migration job secret scope; production DB names never end in `_test`.

## 11. Test harness — three groups
| Group | Role(s) | Harness |
|---|---|---|
| **A — Schema/Migration** | `eora_owner` + catalog reads | empty `eora_test` each run; migrate → rollback → migrate; schema guard; grants matrix; definer allowlist |
| **B — Runtime authorization** | HTTP → `eora_app`, `eora_auth_web`; fixtures via provisioning (`eora_provisioner`) and a test delivery worker (`eora_auth_delivery`) with a capturing mail transport | no outer test transaction; unique tenants per test |
| **C — Isolation/RLS/privilege** | `eora_app`, `eora_auth_web`, `eora_auth_delivery` **directly** (raw SQL, incl. through PgBouncer) | explicit `BEGIN`/`set_config`; parallel processes; abuse cases call functions directly |
Bootstrap of B and C: for every connection, `rolsuper = false AND rolbypassrls = false` and `current_user` is the expected role; otherwise abort. No RLS or privilege test runs as superuser or owner.

## 12. Security test matrix
| ID | Grp | Test | Expected |
|---|---|---|---|
| T-RLS-GUARD / T-MIG-01 / T-GRANT-01 | A | §5.4; round-trip; grants | pass |
| **Login & identity (P1-01)** | | | |
| T-LOGIN-01 | B | valid login with no prior context (fresh pooled connection) | 200, session regenerated |
| T-LOGIN-02 | B | unknown email vs wrong password vs locked vs disabled | identical status/body; timing within bound |
| T-LOGIN-03 | C | `eora_app` calls `auth_api.login_lookup` / selects `auth.identities` | permission denied |
| T-LOGIN-04 | C | `eora_auth_web` calls `login_lookup('%')`, `login_lookup('a@x.com'' OR 1=1 --')`, empty, 1 MB string | 0 rows / 0 rows / 0 rows / rejected (length) |
| T-LOGIN-05 | C | `eora_auth_web` selects `public.users`, `memberships`, `auth.identities` directly | permission denied |
| T-LOGIN-06 | C | `eora_app` with A context reads `users` | only A members + self; no email of non-members |
| T-LOGIN-07 | B | N failed logins on one account from many IPs | lockout enforced in the DB (`login_failures` window), not only HTTP; locked account gets no hash |
| **Invitation & first admin (P1-02)** | | | |
| T-INV-01 | B | provision → delivery worker → captured message contains link → accept → login | membership ACTIVE; admin sees only the new tenant |
| T-INV-02 | B | accept after expiry | uniform failure; membership stays INVITED |
| T-INV-03 | B | accept twice (replay) | second attempt fails; no second password set |
| T-INV-04 | B | random/garbled/other tenant's secret | uniform failure |
| T-INV-05 | B | mail transport fails 2×, then succeeds | retries rotate the secret; only the last secret works; status visible to inviter |
| T-INV-06 | C | failure injected inside the provisioning tenant transaction | no tenant/company/plant/membership/assignment/outbox rows; identity without membership cannot log in |
| T-INV-07 | C | `eora_app`/`eora_auth_web` call `issue_invitation_secret` or read `auth.invitations` | permission denied |
| T-INV-08 | B | acceptance succeeds but membership activation fails twice | `invitation.activation_failed` event + admin notification; no web function exposes pending memberships |
| T-INV-09 | C | `eora_auth_web` calls `invite_identity` twice for a new and twice for an existing email | same id per email; returned ids random (UUIDv4); no password or membership created; responses indistinguishable |
| T-IDN-01 | B | tenant admin invites a **never-registered** email | identity + profile created atomically; membership `INVITED` inserted under RLS; invitation delivered; acceptance sets password; login works |
| T-IDN-02 | B | tenant admin invites an email that already has an identity (member of another tenant) | no new identity/profile; membership `INVITED` in this tenant; invitation delivered; acceptance does **not** change the existing password; user now sees both tenants in the picker |
| T-IDN-03 | C | `eora_auth_web` calls `provision_identity`; `eora_provisioner` calls `invite_identity`; `eora_app` calls either | permission denied (one grantee each) |
| T-IDN-04 | C | definer path: identity insert succeeds but profile insert fails (forced) | both rolled back — never one without the other |
| T-IDN-05 | C | identity without membership tries login / password reset | login fails uniformly (no password); reset delivers nothing usable until a membership invitation exists; cleanup removes it after 7 days |
| T-SEVA-01 | C | `eora_auth_web` calls `record_security_event` trying to set a tenant id or spoofed source | impossible (no parameter) / rejected |
| T-SEVA-02 | C | `eora_auth_definer`-owned functions: catalogue shows INSERT-only on `security_events`; any SELECT/UPDATE/DELETE path from `eora_auth_web`/`eora_auth_delivery` | none exists |
| T-SEVA-03 | C | `eora_app` inserts an event with `source = 'auth_web'` | WITH CHECK violation |
| T-SEVA-04 | B | invitation accepted / reset consumed | event with tenant from stored rows; no secret, no email, no password in the row |
| T-DLV-01 | C | worker crashes after `claim_delivery`, before issuance | after lease expiry another worker reclaims; message sent once |
| T-DLV-02 | C | crash after issuance, before send | reclaim rotates the secret; only the new secret works |
| T-DLV-03 | C | crash after send, before `mark_delivery_result` | reclaim sends a second message with a new secret; first link fails uniformly; second works |
| T-DLV-04 | C | stale worker resumes after its lease expired and calls `issue_*` / `mark_delivery_result` | rejected (claim token mismatch); it does not send |
| T-DLV-05 | C | two workers race to claim | exactly one claim (SKIP LOCKED) |
| T-DLV-06 | C | provider fails 5× | `DEAD`, `delivery.dead` event, alert; admin resend creates a new delivery |
| T-DLV-07 | B | first-admin invitation `DEAD` → operator console resend | one-time link printed (not logged), audited; admin can accept |
| T-DLV-08 | C | lease expiry while a slow send is still running | the slow worker's later `mark_delivery_result` is rejected; recipient may hold two messages, only the newest secret works |
| **Password reset (P1-03)** | | | |
| T-RST-01 | C | `eora_app` calls `request_password_reset`, `issue_reset_secret`, `consume_password_reset` | permission denied |
| T-RST-02 | C | `eora_auth_web` calls `issue_reset_secret` | permission denied |
| T-RST-03 | C | `eora_auth_web` calls `request_password_reset(<privileged email>)` | returns nothing; no secret obtainable through any web function |
| T-RST-04 | C | `eora_auth_delivery` calls `consume_password_reset` | permission denied |
| T-RST-05 | B | full flow with capturing mail transport | password changed; all sessions of the user invalidated; MFA (when enrolled) still required |
| T-RST-06 | B | replay consumed secret; expired secret; secret of rotated (older) delivery | uniform failure |
| T-RST-07 | B | forgot-password for unknown vs known email | identical response; no row with secret for unknown |
| T-RST-08 | A | no column in any table readable by `eora_app`/`eora_auth_web` contains a raw secret; logs of a full flow contain no secret | pass |
| T-RST-09 | B | reset of an account with privileged roles (no MFA) | tenant admins notified (D-B1-18) |
| **Final security gate — direct-abuse cases (R-13..R-17)** | | | |
| T-ABUSE-01 | C | as `eora_auth_web`: `set_config('app.user_id', <victim>, true)` then call every `auth_api` function it holds | no function changes or reveals anything for the victim based on the setting (catalogue §2.8) |
| T-ABUSE-02 | A | catalogue: no `auth_api` function body references `current_setting('app.` | none found |
| T-ABUSE-03 | C | `set_own_password` / any password-setting function without a delivered secret exists or is executable by any role | does not exist (B1) |
| T-ABUSE-04 | C | as `eora_auth_web`: after 10 recorded failures, try every available function to clear or lower the lockout | lockout unchanged; only a consumed reset secret or expiry clears it |
| T-ABUSE-05 | C | as `eora_auth_web`: forge a "successful login" (any function/argument combination) | no function exists that records success in `auth`; `last_login` only via `security_events` |
| T-ABUSE-06 | C | as `eora_auth_delivery`: `issue_reset_secret` for a delivery id that does not exist / belongs to another kind / an expired or consumed request / a DISABLED identity | rejected, nothing issued |
| T-ABUSE-07 | C | as `eora_auth_delivery`: `enqueue_invitation` for an ACTIVE, REVOKED or > 30-day-old membership, or a random uuid | rejected |
| T-ABUSE-08 | C | cross-tenant invitation abuse: invitation secret issued for tenant B membership; attacker in tenant A tries to accept it with an identity of tenant A, or uses `invite_identity` to attach their own email to B's membership | secret only reaches B's invitee address; acceptance binds to the stored membership/user only; `invite_identity` cannot change a membership's user |
| T-ABUSE-09 | C | recipient address: delivery functions given/claimed rows never take an address from the caller | address always equals `auth.identities.email_normalized` of the stored target |
| T-ABUSE-10 | C | per-target caps: 6th invitation send in 24 h; 4th reset request in an hour | rejected/ignored, security event written |
| T-ABUSE-11 | C | as `eora_app` with A context and an injected `set_config('app.tenant_id', B, true)` inside the same transaction (documents the R-17 limit); with signed context (if D-B1-24 approved) | without D-B1-24: B rows readable (expected limitation, mitigated by parameterized-only SQL — test lives in the threat-model suite); with D-B1-24: 0 rows |
| T-ABUSE-12 | A (arch/static) | raw SQL string concatenation with request input; `DB::raw`/`whereRaw` with variables outside reviewed allowlist; emulated prepares enabled | build fails |
| **Durable security events (P1-04)** | | | |
| T-SEV-01 | B | tenant A user GETs a tenant B record id | 404 problem+json; `security_events` row `access.not_found` with A context, requested id, correlation id; no B data |
| T-SEV-02 | B | command that writes business data then fails with 403 | business rows and `audit_events` rolled back; `access.forbidden` security event present |
| T-SEV-03 | B | DB flush forced to fail | response unchanged; JSON log line present; retry job queued; metric incremented |
| T-SEV-04 | C | `eora_app` with A context inserts security event for tenant B; updates/deletes any event | WITH CHECK violation / permission denied |
| T-SEV-05 | B | auth failure for unknown email | event with `email_hmac`, no email, no password |
| **Isolation, context, session (unchanged from v3)** | | | |
| T-ISO-01..05, 02b; T-MEM-01; T-TEN-01; T-USR-02; T-CTX-01..05; T-PROV-01..07; T-AUTH/SESS/CSRF/COOKIE/CORS/RBAC/ESC/CONC/RATE/AUD/PRIV | B/C | as v3 (T-ISO-01 now asserts the `security_events` row of §8) | as v3 |

## 13. Privilege-escalation prevention
Grantor ⊇ grantee; SoD-06 self-elevation blocked until O-007 thresholds exist; protected columns never mass-assignable; tenant/plant only from session; regeneration on privilege change; `roles_version` cache keys; break-glass time-boxed; no platform → tenant path; runtime roles cannot `SET ROLE`; credential issuance and consumption held by different roles; definer functions with `search_path=''` and one grantee each.

## 14. Risk register
| Risk | Today | Mitigation | Test |
|---|---|---|---|
| Cross-tenant leakage | low / critical | session context, composite FKs, forced RLS, 404 | T-ISO-01/02 |
| Account takeover via SQL injection (reset/invitation) | **was critical (R-11)** → low | issuance and consumption split across roles; `eora_app` has no auth privilege; secrets only in delivery worker + recipient | T-RST-01..04, T-INV-07 |
| Login impossible / identity leakage | **was blocker (R-9)** → low | `auth.identities`, exact-match definer lookup, uniform answers | T-LOGIN-01..06 |
| Unusable first admin | **was blocker (R-10)** → low | after-commit delivery worker, console fallback, rotation | T-INV-01..08 |
| Lost denied-access audit | **was high (R-12)** → low | post-rollback flush + log + retry | T-SEV-01..05 |
| Hash exposure via compromised auth route | low / high | one exact email per call, argon2id, MFA, lockout | T-LOGIN-04/07 |
| Delivery worker compromise | low / high | separate process + credentials, cannot consume, issuance only for recorded requests, caps, rotation, short TTL, issuance alerts | T-RST-04, T-ABUSE-06/07 |
| Spoofed `app.*` settings used as identity proof | **was critical (R-13)** → none in `auth_api` | catalogue rule §2.8; `set_own_password` removed | T-ABUSE-01..03 |
| Forged login success / lockout reset | **was high (R-14)** → low | append-only failures, no success function | T-ABUSE-04/05 |
| Arbitrary-target secret issuance | **was high (R-15)** → low | delivery-id-only issuance with re-validation, address from identity | T-ABUSE-06..10 |
| Arbitrary SQL execution as `eora_app` bypassing tenant RLS via `set_config` | medium / critical | parameterized-only, native prepares, static checks; signed context PoC (§5.5, D-B1-24) | T-ABUSE-11/12, PoC-CTX |
| Stranded deliveries (worker crash) | **was medium (R-21)** → low | lease, reclaim, rotation, bounded retries, resend | T-DLV-01..08 |
| Invitation of new users impossible | **was blocker (R-18)** → low | identity + profile in one definer transaction | T-IDN-01..05 |
| Lost auth security events | **was high (R-19)** → low | INSERT-only path for the definer | T-SEVA-01..04 |
| RLS bypass (superuser/owner) | high until B1 / critical | role split, guards, bootstrap assertion | T-RLS-GUARD |
| Pool context leakage | low / critical | transaction-local context, NULLIF | T-ISO-03, T-CTX-01 |
| False-positive tests | high today / high | groups A/B/C, direct-role abuse tests | bootstrap |
| Destructive command on production | low / critical | §10 | guard tests |

## 15. Acceptance criteria (B1 exit)
1. §9 objects exist; runtime, auth, delivery and test connections use their non-superuser roles; T-RLS-GUARD, T-GRANT-01 green.
2. All §12 tests green on every PR (docker job includes PgBouncer and a capturing mail transport).
3. Provision → invitation delivered → accept → login → tenant picker → plant switch → forgot/reset → logout works same-origin in Playwright, cookies asserted.
4. Migrate/rollback round-trip; §10 hardening implemented.
5. OpenAPI documents all B1 endpoints incl. errors; typed client regenerated; no drift.
6. Docs updated (ADR-0001/0006, docs 05, 17, 18, 24, register). B2 not started.

## 16. Recommended B1 implementation sequence (after approval; each step its own commit/PR section)
0. (Separate approval) Security PoC: PoC-CTX-01..13 in a throw-away environment; report before any B1 decision on D-B1-24.
1. DB bootstrap script: roles (§5.1), schemas, grants; Compose/CI switch away from the superuser; group A harness + schema guard.
2. Tenancy tables (`tenants`, `companies`, `plants`, calendars) with forced RLS; group C isolation tests T-ISO-02/02b.
3. `TenantContext`, `TenantTransaction`, middleware priority, job middleware, PgBouncer in Compose/CI; T-ISO-03/04, T-CTX.
4. `security_events` + buffered post-transaction flush (needed by every later step's denials); T-SEV.
5. Identity: `auth` schema, definer functions, auth connection, login/logout/me/picker, session/CSRF/cookies; T-LOGIN, T-AUTH, T-SESS, T-CSRF, T-COOKIE.
6. Delivery worker + outbox minimal + invitation + password reset; T-INV, T-RST.
7. RBAC: roles, assignments, policies, escalation rules; T-RBAC, T-ESC.
8. Provisioning command (bootstrap admin role D-B1-16); T-PROV, Playwright end-to-end.
9. Business audit (`audit_events`), rate limits, OpenAPI, docs.

## 17. Final decision proposal
### 17.0 Summary (no OPEN item is treated as approved)
| Group | Content |
|---|---|
| **A. Architecture candidates** (direction approved D-053; each still needs formal approval) | role separation (§5.1), forced RLS with NULLIF, identity split + auth function catalogue (§2.1–§2.9), provisioning in three steps, TenantTransaction lifecycle, durable security events, test groups A/B/C, scope columns, ids (D-B1-10 amended), calendars in B1, error codes |
| **B. Mandatory owner decisions** | D-B1-01, 02, 03, 04, 08, 09, 10, 13, 14, 15, 16, 18, 19, 20, 23, 23b, 24 (§17 A) |
| **C. Security PoC required before coding** | PoC-CTX-01..13 (D-B1-24); native prepares through PgBouncer (PoC-CTX-13 / D-B1-09); DB-level abuse suites for auth definer functions (T-ABUSE, T-RST, T-IDN, T-SEVA, T-DLV) as the first B1 deliverable |
| **D. Operational parameters** | D-B1-05, 06, 07, 11, 21 (incl. lease 2 min, 5 attempts, backoff 1/5/15/60 min), 22 |
| **E. Safely deferred** | D-B1-12 (retention), MFA delivery in B1.1 (before pilot) |
| **OPEN (untouched)** | O-003, O-007, O-012, O-013, O-015, O-021 |

Nothing below is decided until the owner approves it. Numbers are recommendations with rationale.

### A. Mandatory before B1 implementation starts
| ID | Decision | Recommendation | Risk if postponed |
|---|---|---|---|
| D-B1-01 | DB roles | §5.1 (seven roles incl. auth web/delivery split) | B1 cannot start safely; tests stay false-positive |
| D-B1-02 | Scope columns | typed nullable columns + CHECK + composite FKs | role assignment schema undefined |
| D-B1-03 | Global identity | `auth.identities` + definer functions per §2.1/§2.2 | login and credential protection undefined |
| D-B1-04 | Auth feature set | B1: login, logout, me, picker, plant switch, invitations, password reset; **TOTP MFA for privileged roles in B1.1 before pilot** | without a fixed set, scope creep; MFA postponed = privileged accounts on password only until B1.1 |
| D-B1-08 | Error codes | add `CSRF_TOKEN_MISMATCH` (419); use `SESSION_EXPIRED`, `SCOPE_FORBIDDEN` | frontend cannot handle CSRF expiry cleanly |
| D-B1-09 *(revised v5)* | Pooler | native server-side prepared statements (`PDO::ATTR_EMULATE_PREPARES = false`) through PgBouncer ≥ 1.21 with `max_prepared_statements` enabled; pinned maintained image | emulated prepares interpolate client-side and allow multi-statement simple queries — larger SQL-injection blast radius; T-ISO-03 cannot run without a pooler |
| D-B1-10 *(amended v6)* | Ids | UUIDv7 (app-generated + DB default) for tenant-owned rows; **random UUIDv4 generated in the definer for global user ids** (no creation-time signal, R-20) | inconsistent id strategy; account enumeration via id timestamps |
| D-B1-13 | Provisioning | artisan command, three idempotent steps §2.5 | no way to create a tenant |
| D-B1-14 | Calendars in B1 | yes | B3/B5 blocked later |
| D-B1-15 | Test harness | groups A/B/C §11 | false-positive isolation tests |
| D-B1-16 | Bootstrap admin role | `TENANT_ADMIN` = `core.users_roles` (VCP) + `core.audit` (V) only | first admin cannot onboard anyone; touches O-007 boundary — owner must confirm |
| D-B1-18 | Secret delivery path | separate delivery worker role/process; mail transport (dev: capturing/mailpit, prod: owner-chosen SMTP/API provider); console fallback for first admin; privileged-reset notification to tenant admins | invitations and resets cannot work; takeover protection incomplete |
| D-B1-19 | Membership activation | ACTIVE on acceptance of an admin-created invitation | onboarding flow undefined |
| D-B1-23 | Password change in B1 | no direct change function; signed-in users change passwords through the email-confirmed reset flow; direct change returns with MFA step-up in B1.1 only with a DB-verifiable proof (one-time step-up secret issued by the delivery path or verified TOTP) | without this decision the removed `set_own_password` would be re-introduced unsafely |
| D-B1-23b | Where password verification happens | **(a) recommended:** argon2id in PHP, `login_lookup` returns the hash of a non-locked known email (residual §2.2); (b) DB-side `pgcrypto` bcrypt verification (`attempt_login(email, password)` → outcome; no hash ever leaves the DB) — removes the residual but moves ~250 ms CPU per login to PostgreSQL (shift-start login bursts) and sends plaintext passwords to the DB (needs TLS and parameter-logging disabled for the role) | choosing late changes the identity schema |
| D-B1-24 | Signed tenant context | **PREFERRED FOR PoC — IMPLEMENTATION PENDING APPROVAL** (owner, 2026-10-09). Design §5.5 (`ctx`, `ctx_api`, `eora_ctx_verifier`); PoC-CTX-01..13 mandatory before any implementation decision; no automatic fallback if the PoC fails | without it RLS contains bugs only; SQL injection as `eora_app` could cross tenants and parameterized-only rules carry all the weight |
| D-B1-20 | Security-event durability | buffered post-transaction flush + log + retry job (§8) | denied-access audit lost (T-ISO-01 fails) |

### B. Operational parameters requiring approval
| ID | Parameter | Recommendation (rationale) | Trade-off |
|---|---|---|---|
| D-B1-05 | Session lifetime | office: idle 30 min, absolute 12 h; shared shop-floor devices: idle 15 min, absolute = shift (8–12 h); platform max 24 h | security vs re-login on the floor |
| D-B1-06 | Login limits | 5/min per account + 20/min per IP, progressive delay; 60/min per IP for allow-listed factory NAT; DB lockout after 10 failures for 15 min | shared NAT lockouts vs brute force |
| D-B1-07 | Password policy | argon2id m = 64 MiB, t = 3, p = 1; min length 12; offline breached-password list | memory per login vs cracking resistance |
| D-B1-11 | Timeouts | `statement_timeout` 5 s read / 10 s command / 120 s job; `lock_timeout` 3 s; `idle_in_transaction_session_timeout` 30 s | spurious failures at peaks vs runaway locks |
| D-B1-21 | Secret TTLs and throttles | invitation 72 h; reset 30 min; ≤ 3 open reset requests per identity per hour; delivery retries 5 with backoff, rotating secret | shorter = safer, more resends |
| D-B1-22 | Pepper/HMAC key for `email_hmac`, `ip_hash` | 256-bit key from the secrets manager, rotated yearly (old key kept for lookup) | rotation complexity vs correlation ability |


### C. Safe to defer
| ID | Decision | Why safe now | Risk if deferred too long | Deadline |
|---|---|---|---|---|
| D-B1-12 | Audit/security-event retention + partitioning | V1 deletes nothing | storage growth; contractual retention unknown | before pilot contract |
| D-B1-17 | (merged into D-B1-20) | — | — | — |
| MFA delivery (part of D-B1-04) | B1.1 | privileged accounts protected by password + lockout until then | account takeover by password compromise | before pilot |

**Still OPEN, not decided here:** O-007 (approval thresholds, role-template content beyond D-B1-16, support impersonation), O-012, O-013, O-015, O-021.

## 18. Code dependencies found during review (not implemented here)
| Dependency | Slice |
|---|---|
| App, tests, Compose, CI stop using the superuser; role bootstrap script | B1 |
| Second and third DB connections (`pgsql_auth`, `pgsql_delivery`), separate worker process for delivery | B1 |
| Middleware priority: `SubstituteBindings` inside `TenantTransaction`; post-transaction security-event flush | B1 |
| `TestDatabaseGuard` host allowlist; `prohibitDestructiveCommands` in production | B1 |
| `CSRF_TOKEN_MISMATCH` mapping in `ProblemDetails` | B1 |
| PgBouncer + capturing mail transport in Compose and CI | B1 |

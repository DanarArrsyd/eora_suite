# ADR-0006 — Web/API deployment topology, Sanctum cookie auth, CSRF and CORS

- **Status:** Accepted (D-016, DD-05) · **Date:** 2026-10-08

## Context
Owner chose Next.js + React + TypeScript for web/PWA and Laravel API with Sanctum cookie-based authentication, and requires a secure topology and cookie/CSRF/CORS strategy.

## Decision
### Topology — same origin
```
Browser / installed PWA
   │  https://app.<product-domain>   (one origin)
   ▼
CDN/WAF → Load balancer / reverse proxy (path routing)
   ├── /api/*, /sanctum/*, /broadcasting/auth, /exports/download/* ──► Laravel API (stateless replicas, PHP-FPM or Octane later)
   ├── /realtime/*  (WebSocket upgrade) ───────────────────────────► Reverb
   └── everything else ─────────────────────────────────────────────► Next.js (Node replicas)
Laravel ─► PostgreSQL (via pooler) · Redis (session, cache, queue) · S3-compatible storage
Workers (Horizon) ─► same DB/Redis/storage; no inbound traffic
```
- Browser sees **one origin** → no CORS in production. Laravel CORS config allows nothing cross-origin except explicitly listed local dev origins.
- Next.js is a UI server only: no business logic, no database access, no stored API tokens.
- Separate hostnames for staging/production; preview environments get their own origin and their own API.

### Session and cookies
- Sanctum **stateful SPA authentication** (session cookie), not personal access tokens, for web/PWA.
- Session cookie: name with `__Host-` prefix, `Secure`, `HttpOnly`, `Path=/`, **no Domain attribute**, `SameSite=Lax`. Session store Redis; idle timeout and absolute lifetime per tenant policy; rotate session ID on login, tenant switch and privilege elevation.
- `XSRF-TOKEN` cookie (readable by JS, `Secure`, `SameSite=Lax`) issued by `GET /sanctum/csrf-cookie`; client sends `X-XSRF-TOKEN` on every state-changing request. Laravel validates CSRF on all non-GET routes. GET routes never change state.
- `SANCTUM_STATEFUL_DOMAINS` = the single app host. `SESSION_SECURE_COOKIE=true`.
- Active tenant/plant stored in server session (DD-07); switching tenant = explicit endpoint + session regeneration + audit.
- MFA (TOTP/WebAuthn) for privileged roles; step-up re-authentication for approvals and reversals (doc 05, O-014).

### Next.js data access
- Client Components call `/api/*` with `credentials: 'include'` through the generated typed client (OpenAPI).
- Server Components/Route Handlers may perform **read-only** GETs to the internal Laravel URL, forwarding the incoming `Cookie` header only; no mutations server-side from Next.js; never cache per-user responses in shared caches (`cache: 'no-store'` for authenticated data).
- Middleware in Next.js only checks presence of a session for redirects (UX); authorization is always Laravel's.

### Headers and browser hardening
Strict CSP (nonce-based scripts, `connect-src 'self'` + realtime path), `frame-ancestors 'none'`, HSTS, `Referrer-Policy: same-origin`, `Permissions-Policy: camera=(self)` (scanner) and nothing else; download responses `Content-Disposition: attachment` with sanitized filenames.

### PWA specifics
- Service worker scope `/`; caches only static assets and an offline shell; **never** caches `/api/*` responses containing tenant data unless explicitly whitelisted read data (doc 07).
- Offline drafts (O-009 scope) stored in IndexedDB, encrypted with a per-session key, cleared on logout/tenant switch; posting always online with server revalidation.
- Session expiry while offline → draft kept, user re-authenticates, server revalidates before posting.

### Realtime
Reverb private channels authorized via `/broadcasting/auth` (session cookie + CSRF). Channel names include tenant and user (ADR-0001). Realtime messages carry IDs and minimal text; clients refetch details through the API.

### Machine clients (later)
Integrations/scanners using API tokens are a separate Sanctum token guard with scoped abilities, tenant-bound, never used by the browser.

## Consequences
+ No CORS surface, no tokens in JS storage, standard CSRF model.
− Requires path-routing reverse proxy; Next.js and Laravel must be deployed together per environment.
− Local development needs a proxy (e.g. Next.js rewrites or a dev reverse proxy) to keep same-origin behaviour.

## Verification
Tests: CSRF required on all mutations; cookie attributes asserted; cross-origin request rejected; tenant switch regenerates session; SSR fetch never shares cache between users; service worker does not cache `/api/*`; logout clears IndexedDB drafts.

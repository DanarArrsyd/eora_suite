# 28 — M1 Scope: Access (B1) — lembar persetujuan

**Status:** **DISETUJUI 2026-10-11 (D-066)** — owner: "Setuju scope M1". Implementasi di `feat/m1-access`.
**Basis:** doc 25 v18 (§15 acceptance, §16 urutan), ADR-0001 Amendment 2, D-055..D-057, D-060, doc 18 §1, doc 22, `docs/uiux/`.
**Tujuan M1:** Anda bisa **login, memilih tenant/plant, mengundang user, memberi role, reset password dan logout** di aplikasi yang berjalan, di atas fondasi isolasi tenant yang sudah terbukti di PoC.

## 1. Dalam scope
**Backend (`apps/api`), mengikuti urutan doc 25 §16**
1. Bootstrap DB: role §5.1 + `eora_ctx_verifier`, schema, grant; aplikasi, test dan CI berhenti memakai superuser; schema guard grup A **termasuk allowlist definer persis** (D-056 P-04).
2. Tabel tenancy: `tenants`, `companies`, `plants`, kalender bisnis (D-B1-14), dengan FORCE RLS memakai signed context.
3. **Signed tenant context** `ctx_api.context()` (ADR-0001 Amendment 2), `TenantContext`/`TenantTransaction` dengan BEGIN digabung binding read, job middleware, PgBouncer di Compose dan CI.
4. Image `apps/api`: libpq ≥ 17 dari apt.postgresql.org; CI mencetak dan memverifikasi versi libpq (D-056 P-05).
5. `security_events` dengan flush setelah transaksi.
6. Identitas: schema `auth`, fungsi definer, login/logout/me/picker/plant switch, sesi + CSRF + cookie (argon2id di PHP, D-B1-23b a).
7. Delivery worker + outbox minimal + undangan + reset password (dev: mailpit).
8. RBAC: template role doc 18 §1 (D-060), role assignment dengan scope, policy, aturan anti-eskalasi (SoD-06).
9. Command provisioning tenant (`TENANT_ADMIN` awal, D-B1-16); password role DB lewat verifier SCRAM (P-06).
10. `audit_events`, rate limit (D-B1-06), OpenAPI + typed client tanpa drift.

**Frontend (`apps/web`), mengikuti design system yang disetujui**
- Layar: Login, Terima undangan, Lupa/Reset password, Tenant picker, Plant switch di topbar, Logout.
- Administration → **Users** (daftar, undang, nonaktifkan) dan **Roles & Permissions** (assign template role + scope).
- Data asli lewat typed client. Label "Mock data" tetap ada di layar modul lain.
- Layar login/auth belum ada di prototype v2.1, jadi dirancang mengikuti `docs/uiux` dengan skill Frontend Design. Anda menilainya saat demo.

**Untuk testing Anda**
- Seeder demo yang bisa di-reset: 1 tenant sintetis ("Demo Stamping"), 1 company, 1 plant, user per template role.
- Skrip UAT langkah demi langkah.

## 2. Di luar scope M1
- TOTP MFA: B1.1, wajib sebelum pilot (D-B1-04).
- SSO.
- Layar kelola company/plant/work center: M2; di M1 plant dibuat lewat provisioning/seeder.
- Layar Audit Log dan Approval Policies: M7.
- Provider email produksi: M6.
- Retensi audit: D-B1-12, sebelum kontrak pilot.
- Semua master data, ledger dan transaksi bisnis.

## 3. Acceptance (exit M1)
1. Semua tes doc 25 §12 hijau di setiap PR, dijalankan sebagai role non-superuser: T-ISO-01..05, T-CTX (PoC-CTX-01..14 + 05b yang diport), T-LOGIN, T-INV, T-RST, T-RBAC, T-ESC, T-PROV, T-SEV, dan seterusnya.
2. **Gate performa:** context ≤ 0,3 ms p95 per transaksi dan ≤ 15 µs per referensi tabel ber-RLS (D-055), diukur di CI.
3. Playwright same-origin: provision → undangan terkirim → terima → login → picker → plant switch → lupa/reset → logout, termasuk pengecekan cookie (doc 25 §15.3). ✅ *(2026-10-14: CI job `stack-e2e`, `apps/web/e2e/stack/m1-access.spec.ts`; lihat `apps/api/README.md` §Stack E2E)*
4. Round-trip migrate/rollback dan hardening §10.
5. OpenAPI mencakup semua endpoint B1 termasuk error; typed client diregenerasi tanpa drift.
6. Quality gate frontend doc 22 §4 hijau (lint, typecheck, unit, e2e, axe).
7. Dokumen ter-update dalam PR yang sama (ADR-0001/0006, docs 05, 17, 18, 24, 13).
8. **Demo ke owner:** Anda menjalankan skrip UAT di lokal dan menyatakan OK.

## 4. Cara kerja
- Branch `feat/m1-access`, beberapa PR berurutan sesuai langkah §1. CI hijau di setiap PR, tanpa auto-merge, push dan merge atas instruksi Anda.
- Berhenti hanya bila ada temuan keamanan atau keputusan baru yang benar-benar dibutuhkan. Hal-hal lain dikerjakan sampai demo.
- Ukuran: **milestone terbesar** di roadmap (fondasi keamanan). Tidak ada tanggal yang dijanjikan.

**Pilihan owner:** ☑ Setuju scope M1 (2026-10-11)

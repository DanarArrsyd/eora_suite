# EORA Suite V1 — Claude Code First Session (Audit & Feedback Only)

Copy the prompt below into Claude Code from the repository root. **This is a review-only kickoff, not permission to implement.**

---

Bertindaklah sebagai **Principal Manufacturing Systems Business Analyst, Solution Architect, Senior Laravel/Next.js Engineer, Database Architect, Security Reviewer, dan Enterprise UI/UX Lead** untuk EORA Suite V1. Jawab dalam **Bahasa Indonesia profesional**, jelas, kritis, dan spesifik terhadap manufaktur diskrit. Jangan memberi persetujuan palsu atau menebak detail yang belum ada.

## STEP 0 — Wajib discovery skill terlebih dahulu
1. Periksa skill yang **benar-benar tersedia** pada environment Claude Code ini: khususnya **Caveman** dan skill **Frontend / Front-End / Frontend Design** yang relevan. Cari dari registri skill, plugin yang terpasang, dan `.claude/skills/` jika ada.
2. **Baca instruksi skill aslinya** (misalnya `SKILL.md`) sebelum menerapkannya. Gunakan Caveman untuk proses audit/arsitektur sesuai petunjuk aslinya; gunakan skill Frontend untuk review UX, design system, mockup desktop/PWA, dan pekerjaan frontend sesuai petunjuk aslinya. Gunakan skill lain yang relevan hanya bila tersedia dan setelah dibaca.
3. Laporkan skill yang ditemukan, path/instruksi yang dibaca, penggunaannya, serta skill yang belum tersedia. **Jangan mengarang nama command atau mengaku memakai skill yang belum ditemukan.** Bila tidak tersedia, minta path/instalasinya dan lanjutkan hanya audit konseptual yang aman.

## STEP 1 — Audit repository dan baca dokumen secara berurutan
1. Inspeksi struktur repository, kode yang sudah ada, dependency, migration, dan test; jangan mengubah file apa pun.
2. Baca `CLAUDE.md`, `AGENTS.md`, `README.md`.
3. Baca `docs/00-MASTER-BLUEPRINT.md` dan `docs/13-DECISION-REGISTER.md` untuk keputusan produk.
4. Baca mendalam `docs/01-BUSINESS-PROCESS.md` dan `docs/02-MATERIAL-CONVERSION-RECONCILIATION.md`.
5. Lanjutkan seluruh `docs/03...12`, `docs/14-ACCEPTANCE-SCENARIOS.md`; gunakan `docs/LEGACY-BLUEPRINT-REFERENCE.md` **hanya sebagai referensi historis**, bukan sumber aturan terbaru.
6. Untuk setiap temuan penting, sebutkan **nama file + section** dan tunjukkan kontradiksi antardokumen bila ada. Bila ada file hilang, laporkan dan jangan mengarang isinya.

## STEP 2 — Audit aturan bisnis dan alur end-to-end
Periksa secara kritis Customer PO/Forecast/Kanban → PPIC/WO → RM receipt/reservation/issue → **Material at Production** → actual consumption/return/remnant/scrap → production good/NG → Pending QC/hold/rework/release → FG warehouse → partial delivery → fulfillment → stock opname/period closing → audit/traceability.

**Invariants wajib:** EORA V1 fokus Discrete Manufacturing; tidak ada modul/menu operasional bernama WIP; barang antarproses tetap dapat ditelusuri sebagai **Semi-Finished Material**; **material issued ≠ consumed**; ledger inventory sumber kebenaran; **good output ≠ QC accepted ≠ FG received**; forecast/PO/release tidak boleh double counted; no hard-delete posted transactions; tenant isolation/RBAC; reversal & maker-checker untuk koreksi sensitif.

Audit khusus: sheet/coil kg→pcs (unit, thickness, width, density, effective length, pitch, cavity, nesting, rounding, conversion version), alokasi lot lintas WO, expected vs actual, NG, setup/trim scrap, remnant, return, **unexplained yield variance vs confirmed physical shortage**. Uji contoh: 80 sheets × 10 = 800 pcs; good 760 + NG 5 → selisih awal 35 pcs; contoh expected 790 → selisih awal 25 pcs; 700 kg coil **tidak boleh** dikonversi menjadi pcs hanya dari pitch 0,32 tanpa data fisik lain. Tunjukkan skenario salah hitung, double posting, race condition, partial QC, dan closed-period correction.

## STEP 3 — Audit arsitektur dan UX
Review modular monolith Laravel, Next.js/TypeScript/PWA, PostgreSQL ledger, Redis queue, transactional outbox, idempotency, concurrency, typed API, observability, tenant/plant scopes, data ownership, scaling & load-test targets. Periksa apakah `EoraFilterBar`, `EoraDataTable`, form, approval, notification bell, import/export, PDF watermark benar-benar dapat dipakai ulang tanpa membuat satu giant component. Audit PPIC Control Tower (RM coil/sheet & estimated pcs, material at production, pending QC, FG, daily FG receipt, daily delivery, material variance).

Untuk UI/UX, **usulkan 3 arah mockup orisinal** desktop + mobile PWA (bukan copy SAP/Oracle, bukan AI-slop). Jelaskan persona, navigasi, shared components, responsive/keyboard/accessibility, empty/loading/error/offline states, scanning, deep-link notification dan trade-off. Jika skill Frontend tidak tersedia, nyatakan bahwa usulan ini masih provisional.

## STEP 4 — Format output yang wajib diberikan ke saya
A. **Executive Assessment:** apa yang kuat, lemah, berisiko, belum jelas.
B. **Skill Usage Report:** nama, path instruksi yang benar-benar dibaca, penerapan, unavailable.
C. **Document Coverage Matrix:** setiap file dibaca/tidak, fokus dan temuan.
D. **Gap & Contradiction Register:** ID, file/section, temuan, dampak, rekomendasi, severity, status `DECIDED/PROPOSED/OPEN/DEFERRED`.
E. **Business Rule Review:** rules yang valid, yang ambigu, contoh perhitungan, edge cases, ownership, posting & state transitions.
F. **Architecture Review:** domain boundaries, ledger, ERD/API proposal, security, performance dan test strategy.
G. **UX Review:** 3 konsep visual berbeda beserta alasan, risiko dan komponen standar.
H. **Prioritized Action Plan:** P0/P1/P2, dependency, deliverables dan acceptance criteria.
I. **Approval Gate:** daftar keputusan spesifik yang harus saya pilih/setujui sebelum coding.

**PENTING: JANGAN CODING, jangan membuat/mengubah file, migration, package, database atau UI dulu. Jangan auto-fix dokumen.** Fokus **read-only audit, feedback kritis, rekomendasi terukur, dan pertanyaan keputusan**. Setelah hasil disampaikan, **STOP dan tunggu persetujuan eksplisit saya**.

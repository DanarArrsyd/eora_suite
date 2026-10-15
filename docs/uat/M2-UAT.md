# M2 UAT — Master Data (organisasi, part, mitra, routing)

**Untuk:** owner (demo M2, doc 29 §4 butir 9). Skrip ini diisi bertahap: setiap PR yang mengubah layar menambah barisnya sendiri (doc 30 §6). **Data:** tenant sintetis "Demo Stamping" (`eora:demo:seed`), semua isinya fiktif. **Browser:** `http://localhost:3100`.

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat.

## 0. Persiapan
1. Backend terbaru (bootstrap → migrate → api berurutan otomatis):
   ```bash
   docker compose up -d --build --wait
   ```
2. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
3. Login pakai password demo. Tiga akun dipakai: `csa@demo-stamping.test` (admin master data, boleh approve), `ppc@demo-stamping.test` (planner: buat dan submit routing, tidak boleh approve).

## 1. Menu Master Data (D-076, langkah 3b)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `csa`, buka modul **Master Data**. | Grup **Organization** (Plants & Warehouses, Work Centers), **Parts**, **Partners**, **Engineering** (Process Types, Routings). Administration tinggal Company, Users, Roles & Permissions. | |
| 2 | Buka alamat lama `http://localhost:3100/admin/work-centers?show=machines`. | Diarahkan ke `/master/work-centers?show=machines`, seksi Machines aktif. | |

## 2. Routing (langkah 3b)
Siapkan dulu sebagai `csa`: satu mesin di work center PRS-01, satu die di plant P1 yang ditautkan ke item jadi (Work Centers → Die parts), dan process type `BLANK`.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Master Data → Routings → **New routing**: plant P1, item jadi, kode `R1`, Primary, berlaku hari ini. | Langsung terbuka halaman routing: Version 1 **Draft**, belum ada operasi. Tanggal sebelum hari ini tidak bisa dipilih. | |
| 2 | **Edit details** → standard yield `98.5`. | Tampil **98.5 %**. | |
| 3 | **Add operation**: BLANK, PRS-01, cycle 4.5 s; centang mesin dan die. | Daftar mesin hanya milik PRS-01; daftar die hanya die P1 yang tertaut ke item. Operasi 10 muncul dengan mesin dan die-nya. | |
| 4 | **Submit for approval**. | Status **Submitted**, panel Approval: "Waiting for a second person". `ppc` tidak melihat tombol Approve. | |
| 5 | Login `csa`, buka routing yang sama → **Approve**. | Version 1 **Approved**, "In use", tercatat approved by csa. Operasi tidak bisa diubah lagi. | |
| 6 | Login `ppc` → routing yang sama → **New version**: berlaku besok, alasan "Faster press cycle". Submit. | Version 2 Submitted dengan operasi salinan version 1; Version 1 tetap "In use". | |
| 7 | Login `csa` → Version 2 → **Reject** dengan alasan "Cycle terlalu optimis". | Version 2 **Rejected**, alasan tampil. | |
| 8 | Login `ppc` → **Reopen to edit**, ubah cycle time operasi 10, Submit lagi. | Kembali Draft, lalu Submitted. | |
| 9 | Login `csa` → Version 2 → **Withdraw to edit**, ubah satu operasi, Submit, lalu **Approve**. | Ditolak: "A routing version is approved by someone who did not prepare or edit it." — siapa pun yang ikut mengedit tidak boleh approve. | |

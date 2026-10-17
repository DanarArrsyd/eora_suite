# M2 UAT — Master Data (organisasi, part, mitra, routing, BOM, data konversi)

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

## 3. BOM dan map routing (langkah 4b)
Butuh routing R1 dengan versi Approved (bagian 2) dan satu item bahan baku (mis. coil, satuan KG).

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Master Data → BOMs → **New BOM**: item jadi, berlaku hari ini. | Pilihan item hanya barang jadi dan Semi-Finished Material. Langsung terbuka halaman BOM: Version 1 **Draft**. | |
| 2 | **Add line**: coil, 0.485 KG, scrap 2. | Baris 10 tampil **0.485 KG**, scrap **2 %**. Tombol **New map** belum aktif (versi masih Draft). | |
| 3 | Tambah komponen yang sama dua kali tanpa "Purpose". | Ditolak: komponen yang muncul lebih dari sekali wajib diberi kegunaan, mis. "bracket side". | |
| 4 | **Submit for approval**, lalu **New map** → pilih routing R1 versi Approved. | Map revision 1 Draft terbuka; setiap baris BOM punya pilihan operasi dari routing itu. | |
| 5 | Pilih operasi untuk setiap baris → **Save mapping** → **Submit for approval**. | Map Submitted, "1 of 1" baris terpetakan. | |
| 6 | Login `csa` → buka map → **Approve**. | Ditolak: "Approve the BOM version and the routing version first." | |
| 7 | Tutup map → **Approve** versi BOM → buka map lagi → **Approve**. | BOM Version 1 **Approved**, "In use"; map revision 1 **Approved**. | |
| 8 | Di map yang Approved → **New revision**. | Revision 2 Draft dengan pemetaan salinan revision 1; revision 1 tetap Approved sampai revision 2 di-approve. | |

## 4. Data konversi: net weight dan conversion profile (langkah 5b, D-080)
Butuh item jadi BRK-100 dan bahan coil (satuan KG) dengan material spec terisi (tebal, densitas). Validasi harus orang kedua dengan izin `master.conversion.approve`: sebelum mulai, login `csa` → Administration → Users → beri `ppc` role **QCS** (tenant). Data konversi hanya disimpan dan divalidasi; belum ada hitungan GYV/PvS/UMV.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `csa` → Master Data → Engineering → **Conversion Data**. | Dua tab: **Net weights** dan **Conversion profiles**. | |
| 2 | **New net weight**: BRK-100, `0.2905`, berlaku hari ini. | Halaman net weight terbuka: Version 1 **Draft**, **0.2905 kg/pc**. Tanggal sebelum hari ini tidak bisa dipilih. | |
| 3 | **Submit for approval**, lalu **Validate** (masih `csa`). | Ditolak: "Conversion data is validated by someone who did not prepare or edit it." | |
| 4 | Login `ppc` → buka net weight yang sama → **Validate**. | Version 1 **Validated**, "In use today", tercatat validated by ppc. | |
| 5 | Login `csa` → **New version**: berlaku besok, `0.288`, alasan "Drawing rev C" → Submit. Login `ppc` → **Validate**. | Version 1 tetap Validated, berlaku sampai hari ini; Version 2 berlaku mulai besok. | |
| 6 | `ppc` → Version 2 → **Retire** → konfirmasi. | Dialog menjelaskan versi ini belum mulai, jadi tidak pernah berlaku. Status **Retired**, "Retired before it took effect". | |
| 7 | Login `csa` → tab **Conversion profiles** → **New conversion profile**: part BRK-100, material coil, berlaku hari ini. | Halaman profile terbuka: formula **Coil (COIL_V1)**; Thickness dan Density terisi dari material spec, input lain "Missing". Panel Approval: "Before submitting, add every input." | |
| 8 | **Edit inputs** → Parts per stroke `1.5` → Save. | Ditolak di field itu: harus bilangan bulat minimal 1. Setiap input punya satuan tetap (mm, g/cm3, pc/stroke). | |
| 9 | Isi semua input (lebar 120, pitch 320, parts per stroke 1, head + tail 1500, setup 0) → Save → Submit. Login `ppc` → **Validate**. | Version 1 **Validated**; di daftar profile kolom "Version in use" = Version 1. | |
| 10 | Login `ppc` atau `csa` → BOMs → BOM BRK-100 → **New version** → edit baris coil → **Conversion profile**. | Pilihan: "No conversion profile" dan "Coil profile, version 1 in use". Setelah disimpan kolom Conversion profile = **Linked**. Komponen lain tidak menawarkan profile coil ini. | |

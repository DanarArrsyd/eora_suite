# M4 UAT — Alur tipis: pesanan → produksi → QC → FG → kirim

**Untuk:** owner (demo M4, doc 33 §4 butir 8). Skrip ini diisi bertahap: setiap PR yang mengubah layar menambah barisnya sendiri (doc 30 §6). **Data:** tenant sintetis "Demo Stamping" (`eora:demo:seed` + `eora:demo:master`), semua isinya fiktif. **Browser:** `http://localhost:3100`.

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat.

## 0. Persiapan
1. Kode terbaru:
   ```bash
   git checkout main && git pull --ff-only
   ```
   ```bash
   sh scripts/ensure-dev-secrets.sh
   ```
2. Backend terbaru (bootstrap → migrate → api berurutan otomatis), izin template ke role tenant, master data demo (aman diulang):
   ```bash
   docker compose up -d --build --wait
   ```
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:tenants:sync
   ```
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:demo:master
   ```
3. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
4. Login pakai password demo. Akun: `ppc@demo-stamping.test` (planner: membuat dan mengonfirmasi SO, membuat WO), `csa@demo-stamping.test` (admin: me-release WO; juga menyetujui BOM/routing), `fgw@demo-stamping.test` (gudang FG: hanya melihat SO).

## 1. Sales Order (langkah 1, D-107, D-108)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Demand → **Sales Orders**. | Daftar SO (kosong pada awalnya), tanpa label Mock data. Menu yang sama juga ada di PPIC → Sales Orders. | |
| 2 | **New sales order** → Plant P1, Customer OEM-A → **Create sales order**. | Halaman `SO-P1-<tahun>-0001`, status **Draft**, "No lines yet". | |
| 3 | **Add line** → buka pilihan **Customer part**. | Hanya part customer OEM-A (A-77101-BK = BRK-A100); part customer lain tidak muncul. | |
| 4 | Customer part A-77101-BK, Customer PO `PO-OEMA-5501`, PO line `10`, tanggal kirim pertama: buka kalender. | Kalender asli (bukan input tanggal bawaan browser); hari yang sudah lewat tidak bisa dipilih. | |
| 5 | Pilih tanggal minggu depan, quantity `1000` → **Add line**. | Pesan "Line added to the draft". Line 1 · A-77101-BK, 1000 PCS, satu pengiriman status **Draft**. | |
| 6 | **Add delivery** pada line 1 → tanggal dua minggu lagi, `500`. | Line 1 jadi 1500 PCS (jumlah pengiriman); dua baris pengiriman Draft. | |
| 7 | Buat SO kedua untuk OEM-A dengan Customer PO `PO-OEMA-5501` line `10` yang sama. | Ditolak: "Customer PO PO-OEMA-5501 line 10 is already on SO-P1-…-0001." | |
| 8 | Kembali ke SO pertama → **Confirm order** → konfirmasi. | Status **Confirmed**; pengiriman jadi **Firm**, Outstanding = quantity; tombol Remove/hapus tidak ada lagi. | |
| 9 | **Cancel delivery** pada pengiriman kedua → konfirmasi tanpa alasan. | Ditolak di dialog: alasan minimal 3 karakter. | |
| 10 | Isi alasan "Customer moved the call-off" → **Cancel delivery**. | Pengiriman **Cancelled** dengan alasan + nama Anda; line 1 kembali 1000 PCS; riwayat tetap ada. | |
| 11 | **Add line** pada SO yang sudah Confirmed (PO line `20`, 300 PCS). | Line 2 langsung **Firm** (tanpa konfirmasi ulang). | |
| 12 | SO kedua (masih Draft) → **Cancel draft** dengan alasan. | Status **Cancelled**, tetap ada di daftar; nomornya tidak dipakai ulang. | |
| 13 | Login `fgw` → Demand → Sales Orders → buka SO pertama. | Bisa melihat; tidak ada tombol Add line, Add delivery, Confirm atau Cancel. | |

## 2. Work Order manual (langkah 2, D-109)
Persiapan: BOM dan routing BRK-A100 dari data demo masih **Draft**. Sebagai `ppc` ajukan (Master Data → BOMs / Routings → Submit), lalu sebagai `csa` setujui keduanya.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → PPIC → **Work Orders** → **New work order**. | Pilihan Item hanya barang jadi; Reason berisi Urgent customer order, No MPS yet, Sample or trial, Replacement of NG output, Other. | |
| 2 | Plant P1, BRK-A100, quantity `1200`, due date minggu depan, reason **Other**, note kosong → **Create work order**. | Ditolak: "Explain the work order when the reason is Other." | |
| 3 | Reason **Urgent customer order**, **For delivery**: pengiriman SO dari §1 → **Create work order**. | Halaman `WO-P1-<tahun>-0001`, status **Draft**, "For delivery" menyebut SO, customer dan tanggalnya; quantity WO boleh beda dari quantity pengiriman. | |
| 4 | **Edit** → quantity `1500` → simpan; lalu **Ask for release** → konfirmasi. | Status **Waiting for release**; "You asked for this release"; tidak ada tombol Release untuk Anda. | |
| 5 | Login `csa` → buka WO itu → **Return** dengan alasan "Pakai MPS minggu depan". | Kembali **Draft** dengan kotak "Returned for changes" berisi alasannya. | |
| 6 | Sebagai `ppc` ajukan lagi; login `csa` → **Release**. | Status **Released**; "Copied at release: BOM v1 · routing … v1"; tabel Materials berisi coil dan nut dengan quantity yang dibutuhkan (1500 × per piece). | |
| 7 | Buat WO untuk item FG lain tanpa BOM/routing approved, ajukan, lalu `csa` → **Release**. | Ditolak di dialog: "The item has no approved BOM version and no approved primary routing version at this plant in force today …". | |
| 8 | Sebagai `ppc` → WO yang Released → **Cancel work order** dengan alasan. | Status **Cancelled** dengan alasan; nomor tidak dipakai ulang. | |

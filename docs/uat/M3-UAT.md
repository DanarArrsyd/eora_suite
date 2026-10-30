# M3 UAT — Ledger stok (penerimaan, release, transfer, split, adjustment, reversal, periode)

**Untuk:** owner (demo M3, doc 32 §4 butir 9). Skrip ini diisi bertahap: setiap PR yang mengubah layar menambah barisnya sendiri (doc 30 §6). **Data:** tenant sintetis "Demo Stamping" (`eora:demo:seed` + `eora:demo:master`), semua isinya fiktif. **Browser:** `http://localhost:3100`.

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat.

## 0. Persiapan
1. Backend terbaru (bootstrap → migrate → api berurutan otomatis), lalu izin baru M3 ke role tenant:
   ```bash
   docker compose up -d --build --wait
   ```
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:tenants:sync
   ```
2. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
3. Login pakai password demo. Akun yang dipakai: `mat@demo-stamping.test` (gudang bahan: terima), `qci@demo-stamping.test` (QC incoming: release), `csa@demo-stamping.test` (admin: hanya melihat).

## 1. Penerimaan bahan — RM Receipts (langkah 3, D-062, D-097)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `mat` → Materials → **RM Receipts**. | Daftar penerimaan (kosong pada awalnya), tanpa label Mock data. | |
| 2 | **New receipt** → buka pilihan **Item**. | Hanya bahan baku dan consumable (coil, sheet, mur, oli); barang jadi tidak muncul. | |
| 3 | Plant P1, supplier steel, item coil SPCC, lokasi rak RM, quantity `2500`, nomor heat `HEAT-001` → **Post receipt**. | Muncul konfirmasi "Post this receipt". **Cancel** → tidak ada yang diposting, form tetap terisi. | |
| 4 | **Post receipt** lagi → konfirmasi **Post receipt**. | Pesan `RCV-P1-<tahun>-0001 posted, lot L<YYMM>-0001`. Baris baru: lot internal + "Supplier HEAT-001", status **Blocked** (coil butuh incoming release). | |
| 5 | Terima oli (consumable, tanpa lot) 20 liter ke rak RM. | Lot "No lot", status **Unrestricted** (tidak butuh release). | |
| 6 | Buka baris penerimaan (View). | Detail saja; tidak bisa diedit. Penerimaan salah dikoreksi lewat reversal (langkah berikutnya). | |
| 7 | Login `csa` → RM Receipts. | Daftar terlihat; tombol New receipt nonaktif dengan alasan. | |

## 2. Incoming release (langkah 3, D-062)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `qci` → Materials → **Incoming Release**. | Hanya stok Blocked di gudang RM: coil dari §1.4, 2500 KG. Oli tidak muncul. | |
| 2 | **Release** pada coil → quantity terisi 2500; ubah ke `2000` → **Release** → konfirmasi **Release**. | Pesan `REL-P1-<tahun>-0001 released`. Baris tersisa Blocked **500 KG**. | |
| 3 | **Release** pada sisa coil dengan quantity `9999`. | Ditolak: "Only 500 is on hand there …"; tidak ada yang berubah. | |
| 4 | Release sisa 500. | Baris hilang dari daftar (tidak ada lagi stok Blocked). | |

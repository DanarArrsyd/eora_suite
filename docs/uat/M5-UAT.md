# M5 UAT — Bisa dipakai: perencanaan, rekonsiliasi, Control Tower

**Untuk:** owner (demo M5, doc 34 §4 butir 8). Skrip ini diisi bertahap: setiap PR yang mengubah layar menambah barisnya sendiri (doc 30 §6). **Data:** tenant sintetis "Demo Stamping", semua isinya fiktif. **Browser:** `http://localhost:3100`.

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat.

## 0. Persiapan
1. Kode terbaru dan backend (bootstrap → migrate → api otomatis):
   ```bash
   git checkout main && git pull --ff-only
   ```
   ```bash
   docker compose up -d --build --wait
   ```
2. Izin baru ke role tenant (setiap langkah M5 yang menambah izin):
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:tenants:sync
   ```
3. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
4. Akun: `ppc@demo-stamping.test` (planner), `mat@demo-stamping.test` (gudang bahan), `inv@demo-stamping.test`, `aud@demo-stamping.test` (lihat saja).

## 1. Parameter perencanaan dan inbound schedule (langkah 1, D-121)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Master Data → **Planning Parameters** → **New parameter set** → Plant P1, item BRK-A100, lead time `3`, safety stock kosong → simpan. | Baris BRK-A100 · P1 · **3 days** (Production) · Safety stock **Not configured**. | |
| 2 | Tambah lagi BRK-A100 di P1. | Ditolak: "This item has planning parameters at this plant already; change them instead." | |
| 3 | Buka baris itu → safety stock `100` → simpan. | Safety stock **100 PCS**; "Changed by" ppc. | |
| 4 | Tambah COIL-SPCC-12 di P1, lead time `7`, safety stock `500`. | Lead time **7 days** (Purchase), safety stock 500 KG. | |
| 5 | Safety stock dengan desimal yang tidak dipakai unitnya (mis. `2.5` untuk item PCS). | Ditolak di form: unitnya hanya bilangan bulat. | |
| 6 | Login `mat` → Master Data → Planning Parameters. | Daftar terlihat, tanpa tombol tambah/ubah. | |
| 7 | `mat` → Materials → **Inbound Schedule** → **New inbound line** → P1, SUP-STEEL, COIL-SPCC-12, qty `1500`, tanggal minggu depan → simpan. | `INB-P1-<tahun>-0001`, **Open**, Outstanding 1500 KG, 0 received. | |
| 8 | Materials → **RM Receipts** → New receipt: SUP-STEEL, COIL-SPCC-12, qty `600`, **Against inbound line** = INB-… → post. | Receipt posted. Inbound line: **Partially received**, Outstanding 900, 600 received. | |
| 9 | Buka inbound line → ubah quantity. | Ditolak: sudah ada receipt, quantity dan tanggal tetap. | |
| 10 | Isi **Cancel the rest — reason** "Supplier short" → simpan → konfirmasi. | Status **Cancelled**; 600 tetap received. Receipt baru tidak bisa memilih line itu lagi. | |
| 11 | Login `inv` → Inbound Schedule. | Daftar terlihat, tanpa tombol tambah. | |

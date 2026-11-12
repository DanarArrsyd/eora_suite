# M3 UAT — Ledger stok (penerimaan, release, transfer, split, adjustment, reversal, periode)

**Untuk:** owner (demo M3, doc 32 §4 butir 9). Skrip ini diisi bertahap: setiap PR yang mengubah layar menambah barisnya sendiri (doc 30 §6). **Data:** tenant sintetis "Demo Stamping" (`eora:demo:seed` + `eora:demo:master`), semua isinya fiktif. **Browser:** `http://localhost:3100`.

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat.

**Hasil 2026-11-12 (owner, stack `eora`):** §1–8 OK — "Oke sih untuk 1-8 nya" (D-106, M3 ditutup). Temuan di luar skrip: halaman ikut ter-scroll ke area kosong di bawah menu → diperbaiki PR #63, #64.

## 0. Persiapan
1. Kode terbaru dan secret baru (kalau ada):
   ```bash
   git checkout main && git pull --ff-only
   ```
   ```bash
   sh scripts/ensure-dev-secrets.sh
   ```
2. Backend terbaru (bootstrap → migrate → api berurutan otomatis), lalu izin baru M3 ke role tenant, lalu master data demo (aman diulang; tenant yang sudah termuat dilewati):
   ```bash
   docker compose up -d --build --wait
   ```
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:tenants:sync
   ```
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:demo:master
   ```
   **Jangan jalankan `eora:demo:stock` sebelum §1–8.** Skrip ini mulai dari ledger kosong: daftar penerimaan kosong, nomor dokumen pertama `…-0001`, stok Blocked hanya dari §1. `eora:demo:stock` memposting 5 penerimaan dan 1 release sendiri, sehingga nomor dan isi daftar tidak lagi cocok. Perintah itu untuk demo cepat setelah UAT (atau di tenant demo lain): coil HEAT-A101 2500 KG **Blocked** dan HEAT-A102 1800 KG **Unrestricted** (sudah di-release), sheet 400 **Blocked**, mur 5000 PCS, oli 200 L (tanpa lot) — semua di rak RM-A01; ia hanya mau jalan sekali per tenant dan menolak tenant yang sudah punya posting stok lain.
3. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
4. Login pakai password demo. Akun yang dipakai: `mat@demo-stamping.test` (gudang bahan: terima), `qci@demo-stamping.test` (QC incoming: release), `prl@demo-stamping.test` (production leader: split lot), `inv@demo-stamping.test` (inventory control: minta adjustment), `csa@demo-stamping.test` (admin: melihat; menyetujui adjustment).

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

## 3. Transfer dalam plant — Stock Overview (langkah 4, D-048, D-098)
Siapkan dulu:
- sebagai `csa`: rak RM kedua di gudang RM plant P1 (Master Data → Plants & Warehouses → Locations, mis. `RM-A02`);
- sebagai `mat`: satu penerimaan coil lagi (Materials → RM Receipts, coil SPCC, rak `RM-A01`, quantity `1000`, nomor heat `HEAT-002`) dan **jangan di-release** — §2 sudah me-release semua coil dari §1, dan langkah 1 dan 5 di bawah butuh stok **Blocked**.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `mat` → Inventory → **Stock Overview**. | Stok dari ledger per lokasi, lot dan status: coil Unrestricted dari §1–2, coil Blocked `HEAT-002` 1000 KG dari persiapan di atas, oli. Kolom **Available** = 0 untuk stok Blocked. | |
| 2 | **Transfer** pada coil Unrestricted → buka pilihan **To location**. | Hanya rak RM plant P1 (tidak ada lokasi Material at Production, QC, FG). | |
| 3 | Pilih rak asal yang sama → **Transfer**. | Ditolak sebelum konfirmasi: "Choose another location than the one the stock is in." | |
| 4 | Pilih `RM-A02`, quantity `800` → **Transfer** → konfirmasi **Transfer**. | Pesan `TRF-P1-<tahun>-0001 transferred`. Rak asal berkurang 800, `RM-A02` 800 KG, status tetap Unrestricted, lot sama. | |
| 5 | Transfer coil **Blocked** (`HEAT-002`) ke `RM-A02`. | Berhasil; di `RM-A02` muncul baris Blocked terpisah (status tidak berubah karena transfer). | |
| 6 | Login `qci` → Inventory → Stock Overview → **Transfer** pada baris mana pun. | Stok terlihat; drawer menjelaskan "Your roles do not allow transfers.", tidak ada form. | |

## 4. Split lot / remnant — Material Lots (langkah 5, D-099)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `prl` → Materials → **Material Lots**. | Hanya stok yang punya lot (coil dari §1–3); oli (tanpa lot) tidak muncul. Tanpa label Mock data. | |
| 2 | **Split** pada coil Unrestricted di `RM-A02` → quantity lebih dari on hand → **Split**. | Ditolak sebelum konfirmasi: "Only … is available here …"; tidak ada yang diposting. | |
| 3 | Quantity `300`, lebar `1219`, panjang `2438` → **Split** → konfirmasi **Split**. | Pesan `SPL-P1-<tahun>-0001 split off lot <lot>-01`. Baris induk berkurang 300; baris baru `<lot>-01` 300 KG di lokasi dan status yang sama, keterangan "Split from <lot>" + nomor heat supplier. Total di lokasi tidak berubah. | |
| 4 | Split induk yang sama sekali lagi (mis. `100`). | Anak berikutnya bernomor `<lot>-02`. | |
| 5 | Filter **Origin** = Split. | Hanya lot anak. | |
| 6 | Login `mat` → Materials → Material Lots → **Split**. | Stok terlihat; drawer menjelaskan "Your roles do not allow lot splits.", tidak ada form. | |

## 5. Adjustment — dua orang (langkah 6, D-060, D-100)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `inv` → Inventory → **Adjustments** → **New adjustment request**. Pilih stock line coil di `RM-A02`, Change **Remove from stock**, quantity lebih dari on hand, reason **Other**, note kosong → **Request adjustment**. | Ditolak sebelum konfirmasi: "Only … is on hand there." dan "Explain the adjustment when the reason is Other."; tidak ada yang terkirim. | |
| 2 | Quantity `50`, reason **Damage**, note "Ujung coil penyok" → **Request adjustment** → konfirmasi. | Pesan "Adjustment requested; it waits for a second person to approve it". Baris baru status **Submitted**, Change `−50 KG`. Stock Overview **belum berubah**. | |
| 3 | **Open** permintaan itu (masih sebagai `inv`). | Kotak "You requested this adjustment"; tidak ada tombol Approve/Reject. | |
| 4 | Login `csa` → Inventory → Adjustments → **Open** permintaan → **Approve**. | Judul menjadi `ADJ-P1-<tahun>-0001`, status **Posted**, "Approved: csa, <waktu>". Stock Overview: coil di `RM-A02` berkurang 50. | |
| 5 | Sebagai `inv`, minta **Add to stock** `5` reason **Found stock** pada baris yang sama; login `csa` → **Reject** dengan alasan "Tidak ada lembar hitung". | Status **Rejected** dengan alasannya; stok tidak berubah. | |
| 6 | Sebagai `inv`: **New adjustment request** tanpa stock line, Change **Add to stock**, Location `RM-A01`, Item coil, Lot coil, status **Hold**, quantity `5`, reason **Found stock** → kirim; login `csa` → Approve. | Baris baru coil **Hold** 5 KG di `RM-A01` di Stock Overview (stok ditemukan di tempat yang belum tercatat). | |
| 7 | Login `mat` → menu Inventory. | Adjustments tidak muncul (M3: MAT hanya melihat stok). | |

## 6. Reversal — dua orang (langkah 7, D-060, D-101)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `mat` → Inventory → **Reversals** → **New reversal request** → Document number `ADJ-P1-<tahun>-0099` (tidak ada) → **Request reversal**. | Ditolak sebelum konfirmasi: "No posted document … in the plants you can see." | |
| 2 | Document number adjustment `ADJ-P1-<tahun>-0001` dari §5 (huruf kecil juga boleh), reason "Rusak ternyata di coil lain" → **Request reversal** → konfirmasi. | "Reversal requested …"; baris **Submitted**. Stok belum berubah. Meminta lagi untuk dokumen yang sama → ditolak "already waiting for approval". | |
| 3 | Login `inv` → Reversals → **Open** → lihat **Legs undone** → **Approve**. | Judul `REV-P1-<tahun>-0001`, status **Posted**; coil di `RM-A02` kembali +50. Di Reversals, adjustment tidak bisa dipilih lagi. | |
| 4 | Sebagai `mat`, minta reversal **receipt** coil dari §1 (stok sudah di-transfer/split). Login `inv` → **Approve**. | Ditolak: "The stock this document moved is no longer all there … Reverse what came after it first."; permintaan tetap **Submitted**. **Reject** dengan alasan. | |
| 5 | Login `opr` → menu Inventory. | Reversals tidak muncul (OPR tanpa izin reversal). | |

## 7. Tutup dan reopen periode (langkah 8, D-102)
Persiapan: Tenant Administrator memberi role **CSA** ke satu user lain (mis. `apr@demo-stamping.test`) untuk langkah reopen, karena peminta reopen tidak boleh menyetujui sendiri.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `inv` → Inventory → **Period Close** → **New close request** → buka pilihan **Month**. | Hanya bulan yang sudah lewat (bulan berjalan tidak ada). | |
| 2 | Plant P1, bulan lalu → **Ask to close** → konfirmasi. | Halaman bulan itu: status **Closing**, "You asked to close this month"; hanya tombol **Withdraw close request**. Penerimaan dengan tanggal bulan itu sekarang ditolak (`PERIOD_CLOSED`). | |
| 3 | Login `csa` → buka bulan itu → **Reject close** dengan alasan; minta tutup lagi sebagai `inv`; `csa` → **Close month**. | Setelah reject status **Open** lagi; setelah close status **Closed**, "Closed by csa". | |
| 4 | Sebagai `mat`, minta reversal dokumen bertanggal bulan tertutup tanpa **Evidence**. | Ditolak: "… is in a closed month: give the evidence …". Dengan evidence (mis. `BA-001`) → terkirim; di halaman reversal tertulis "Closed-month correction". Approve oleh `inv` → diposting di bulan berjalan. | |
| 5 | Login `csa` → bulan tertutup → **Ask to reopen** dengan alasan. Coba **Reopen month** sendiri. | Tombol reopen tidak ada untuk peminta ("You asked to reopen …"). | |
| 6 | Login user CSA kedua → bulan itu → **Reopen month**. | Status **Open**, "Last reopened by …"; tercatat sebagai kejadian luar biasa. | |

## 8. Movement Ledger dan cek saldo (langkah 9, D-103)
| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `inv` → Inventory → **Movement Ledger**. | Semua pergerakan stok dari §1–7, terbaru di atas: penerimaan `+`, transfer `−`/`+`, split, adjustment, reversal bertanda "(reversal)". Tanpa label Mock data, tanpa tombol New. | |
| 2 | Filter **Item** = coil, **Movement** = Transfer → **Apply**. | Hanya baris transfer coil. | |
| 3 | Cari nomor dokumen (mis. `rcv-p1`). | Hanya baris dokumen itu. | |
| 4 | **View** pada satu baris. | Detail saja, dengan keterangan "The ledger is never edited …"; tidak ada form. | |
| 5 | **Export** → Excel. | File berisi baris yang sama dengan filter aktif (kolom Document, Movement, Item, Lot, Location, Quantity …). | |
| 6 | Inventory → Stock Overview → filter **Item type** = Raw material. | Hanya stok bahan baku. | |
| 7 | (Operator, di server) `docker compose run --rm --entrypoint php scheduler artisan eora:inventory:verify-balances` | "… tenant(s) checked; 0 with differences." | |

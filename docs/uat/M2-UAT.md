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

## 5. Import Excel (langkah 6d, D-081, D-083)
File contoh ada di `docs/uat/samples/` (01 sampai 17, urutan impor). Isinya pabrik stamping contoh di plant P1: dua part jadi (BRK-A100, BRK-B200), satu Semi-Finished Material (PLT-C300), bahan, tooling, routing, BOM dan map. Tanggal "Effective from" di file = 2027-01-04; setelah tanggal itu lewat, buat ulang file dengan tanggal baru:
```bash
docker compose run --rm -v "$PWD/docs/uat/samples:/out" --entrypoint php db-migrate artisan eora:demo:import-samples /out
```
`ppc` boleh unggah dan submit; commit dilakukan `csa` (izin `exchange.import.post` + izin master tujuan). `csa` juga boleh menjalankan seluruh alur sendiri.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Master Data → **Data Exchange → Import**. | Daftar job impor (kosong atau berisi job lama) dengan filter Status dan Template; tombol **New import**. | |
| 2 | **New import** → Template **Units of measure**. | Tabel kolom (Code, Name, Unit class, Decimals) dengan wajib/tidak dan petunjuk isi; info "Valid rows are saved"; tombol **Download empty template** mengunduh `eora-uoms-v1.xlsx`. | |
| 3 | Pilih file selain .xlsx (mis. .csv). | Langsung ditolak di bawah area file: "Use the Excel template (.xlsx)…". | |
| 4 | Pilih `01-uoms.xlsx` (tombol **Choose file** atau seret ke area file) → **Upload and check**. | Halaman job terbuka; langkah Check berjalan lalu otomatis ke **Review** tanpa reload: 2 baris, 2 valid, 0 error. | |
| 5 | **Submit for commit**. | Status **Submitted**, "Waiting for someone allowed to commit imports." Tombol **Commit** untuk `ppc` tidak aktif dengan alasannya. | |
| 6 | Login `csa` → Import → buka job itu → **Commit**. | Langkah Commit lalu **Result**: status **Committed**, 2 baris Committed, tercatat submitted by ppc dan committed by csa. Satuan M dan L ada di Parts → Units & Conversions. | |
| 7 | Unggah `01-uoms.xlsx` sekali lagi. | Terbuka job yang sama (tidak ada job ganda untuk file yang sama). | |
| 8 | Sebagai `csa`, impor `02` sampai `13` berurutan (upload → submit → commit). | Setiap job **Committed** tanpa baris error; datanya muncul di Items, Customers, Customer Part Mapping, Suppliers, Plants & Warehouses, Work Centers (mesin, dies, die parts). | |
| 9 | Impor `14-process-types.xlsx`. | Process type BLANK sudah dibuat di bagian 2: tab **With errors** terbuka dengan "Process type BLANK already exists."; **Error file** mengunduh baris itu beserta nomor baris dan pesannya (hanya untuk dibaca: perbaikan dilakukan di file asli, karena kolom Row dan Errors tidak diterima template); 4 baris lain valid. Submit + Commit → 4 Committed, tombol **Upload corrected file** tetap ada. | |
| 10 | Buka `15-routings.xlsx` di Excel, ubah Work center satu baris jadi `XXX`, simpan dengan nama lain, unggah. | Info "Everything or nothing"; baris itu error, **Submit for commit** tidak aktif: "Every row must be valid for this template." Tidak ada yang tersimpan. | |
| 11 | Unggah `15-routings.xlsx` asli → Submit → Commit. | 7 baris Committed. Di Routings: BRK-A100, BRK-B200, PLT-C300 masing-masing Version 1 **Draft** dengan operasinya; approval tetap oleh orang kedua. | |
| 12 | Impor `16-boms.xlsx`. | 3 BOM Version 1 **Draft**. | |
| 13 | Unggah `17-bom-routing-maps.xlsx` sebelum versi routing/BOM di-submit. | Setiap baris error: "Submit the BOM version and the routing version first…". **Reject** dengan alasan "Versi belum submit" → status **Rejected**, alasan tampil. | |
| 14 | Submit Version 1 ketiga routing dan ketiga BOM di halamannya, lalu unggah `17` lagi → Submit → Commit. | Job baru dengan "Retry of the previous job"; 4 baris Committed; map tiap BOM berstatus **Draft**. | |
| 15 | Ubah judul kolom `Name` di `05-customers.xlsx` jadi `Nama`, unggah. | Langkah **Check** gagal (tanda silang): "The job stopped" dengan kolom yang hilang dan kolom asing. **Upload corrected file** membuka New import dengan template Customers terpilih. | |
| 16 | Buka halaman job di HP (atau lebar layar 375 px). | Tombol membungkus rapi, langkah dan angka terbaca, baris tampil sebagai kartu "Row N" dengan pesan error-nya. | |

## 6. Export Excel (langkah 7, D-092, D-093)
Setelah menarik `main`, jalankan `docker compose up -d --build --wait` (layanan baru `scheduler` menghapus file export setelah 24 jam). Export memakai daftar persis seperti di layar: filter, pencarian dan urutan.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `csa` → Master Data → **Items** → ketik `brk` di pencarian. | Item yang kodenya diawali BRK muncul (huruf kecil/besar sama saja); pencarian nama tetap "mengandung". | |
| 2 | Filter Type = Finished good, urutkan Code menurun, lalu **Export** → **Excel (.xlsx) — current filters**. | Menu hanya menawarkan Excel dan Print (PDF menyusul). Muncul toast "Excel export queued". | |
| 3 | Tunggu beberapa detik. | Notifikasi lonceng "Export ready": "Items: N rows. Download it from Exports within 24 hours." | |
| 4 | Klik notifikasi (atau Master Data → Data Exchange → **Exports**). | Halaman Exports terbuka dengan banner "Items: Ready until …"; status **Ready**, jumlah baris. | |
| 5 | **Download**, buka file di Excel. | Sheet **Items**: judul kolom sama dengan layar, hanya baris Finished good dengan BRK, urut Code menurun, judul baris beku, ada filter otomatis; kolom Ya/Tidak berisi Yes/No. Sheet **About**: daftar, jumlah baris, pencarian, filter, urutan, waktu data dengan zona waktu, peminta, footer "Generated by EORA Suite \| Exported on …". | |
| 6 | Login `ppc` → buka Master Data → Data Exchange → Exports. | Export milik `csa` tidak terlihat (setiap orang hanya melihat export-nya sendiri). | |
| 7 | Sebagai `csa`, export daftar **Routings** dan **Unit Conversions**. | Kolom "Version in use", "Standard yield %", tanggal dan faktor berupa angka/tanggal di Excel (bisa dijumlah/difilter), bukan teks. | |
| 8 | Cari daftar dengan lebih dari 100.000 baris (atau minta admin menurunkan batas lewat `EORA_EXPORT_MAX_ROWS`), lalu Export. | Ditolak dengan pesan "This list has … rows; an export holds at most …. Narrow the filter or the search." | |
| 9 | Keesokan harinya (lebih dari 24 jam) buka Exports. | Export lama berstatus **Deleted**, tombol Download nonaktif dengan alasan "Deleted after 24 hours. Export the list again." | |

## 7. Penutup M2: data demo lengkap, file error, login satu workspace (langkah 8, D-094)
Setelah menarik `main`, jalankan `docker compose up -d --build --wait`. Bagian ini memakai tenant demo **kedua** supaya tidak bentrok dengan data yang Anda impor sendiri di bagian 5 (ganti `<password demo>` dengan password demo Anda; jangan tulis password di catatan):
```bash
docker compose run --rm --entrypoint php db-migrate artisan eora:demo:seed --code=demo-stamping-2 --password='<password demo>'
docker compose run --rm --entrypoint php db-migrate artisan eora:demo:master --code=demo-stamping-2
```
Akun: `csa@demo-stamping-2.test` dan `ppc@demo-stamping-2.test`. `eora:demo:master` butuh layanan `import-worker` berjalan (bagian dari `docker compose up`).

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Jalankan kedua perintah di atas. | Perintah kedua mencetak 16 baris "… rows committed" lalu "Demo master data loaded into demo-stamping-2: 16 files, 61 rows." Menjalankannya lagi ditolak: "loaded already". | |
| 2 | Login `ppc@demo-stamping-2.test`. | Langsung masuk ke workspace Demo Stamping (satu workspace, tanpa pemilih) dan **tidak** terlempar kembali ke halaman Sign in. | |
| 3 | Master Data → **Import**. | 16 job **Committed**: submitted by ppc untuk satuan, item, spec, konversi satuan, customer, customer part, supplier, process type, routing, BOM; submitted by csa untuk gudang, lokasi, work center, mesin, dies, die parts; semua committed by csa. | |
| 4 | Master Data → **Routings** → BRK-A100 R1. | Version 1 **Draft** dengan 3 operasi (BLANK, FORM, NUTWELD) dan standard yield 98.5 %. Tidak ada versi yang Approved tanpa orang kedua. | |
| 5 | Sebagai `ppc`: **Submit for approval**. Login `csa` → routing yang sama → **Approve**. | Version 1 **Approved**, "In use", approved by csa. | |
| 6 | Sebagai `csa`: Import → **New import** → Template **Items** → file `docs/uat/samples/99-items-with-errors.xlsx` → **Upload and check**. | 6 baris: 1 valid, 5 error dengan pesan per baris: "Unit BOX does not exist…", "Item NUT-M10 is also on row 3 of this file.", "Item type: use one of …", "Drawing rev needs a drawing no.", "Item BRK-A100 already exists." **Error file** berisi kelima baris itu. | |
| 7 | **Submit for commit** → **Commit**. | 1 baris Committed; item **NUT-M10** muncul di Items; baris error tidak tersimpan. | |
| 8 | Master Data → **Routings** → ketik `blank` di pencarian; lalu filter **Work center** = WLD-NUT. (langkah 8b, B2-AT-12) | `blank` menemukan routing yang punya operasi Blanking (BRK-A100, PLT-C300); filter WLD-NUT menyisakan BRK-A100. | |
| 9 | Sebagai `csa`: Work Centers → Dies & tooling → die `D-A100-BL` → pindahkan ke plant lain (bila ada plant kedua), lalu buka routing BRK-A100 versi Approved. (B2-AT-39) | Di kolom Dies & tooling: "D-A100-BL (tooling not available in plant)"; versi tetap terbaca dan tidak berubah. | |

## 8. Pernyataan owner (exit M2, doc 29 §4 butir 9)
| Pernyataan | Hasil |
|---|---|
| Saya sudah menjalankan bagian 1–7 di lokal; catatan "Tidak OK" sudah dibahas atau diterima. M2 boleh ditutup. | |

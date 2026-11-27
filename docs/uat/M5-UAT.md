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

## 2. Forecast dan revisi schedule line (langkah 2, D-122)
Persiapan: `eora:tenants:sync` (izin `demand.forecast`). Satu SO OEM-A confirmed untuk BRK-A100 dengan satu pengiriman Firm, mis. 200 PCS tanggal minggu depan.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Demand → **Forecast** → **New forecast version** → P1, OEM-A, Start from: Empty → simpan. | Halaman `FC-P1-<tahun>-0001`, **Draft**. | |
| 2 | Add line: A-77101-BK, minggu dari tanggal pengiriman SO, qty `500`; Add line: minggu berikutnya `300` → **Save lines**. | "Forecast lines saved"; tabel: minggu pertama Forecast 500, Firm orders 200, **Net forecast 300**; minggu kedua Net 300. | |
| 3 | Buat versi forecast baru lagi untuk OEM-A di P1. | Ditolak: masih ada draft; selesaikan dulu. | |
| 4 | **Activate** → konfirmasi. | Status **Active**; baris tidak bisa diubah lagi. | |
| 5 | New forecast version → Start from: The active version → Activate. | Versi baru Active; versi lama **Superseded** (daftar Forecast). | |
| 6 | Demand → Sales Orders → SO itu → pengiriman Firm → **Revise** → ubah qty jadi `120`, **Add delivery** tanggal minggu berikutnya `80`, Reason "Customer split" → **Revise delivery**. | "Delivery split into 2": baris lama **Superseded** ("Revised; kept with what it delivered"), dua baris Firm **Revision 2**; qty line 200. | |
| 7 | Kembali ke Forecast aktif. | Firm orders per minggu mengikuti baris revisi (120 dan 80); baris Superseded tidak dihitung. | |
| 8 | Alokasikan FG ke salah satu baris revisi (Delivery → Allocation & Pick), lalu coba **Revise** baris itu. | Ditolak: lepas alokasi / kembalikan yang di-stage dulu. | |
| 9 | Login `dlv` → Demand → Forecast. | Daftar terlihat, tanpa tombol tambah. | |

## 3. Master Production Schedule (langkah 3, D-123)
Persiapan: forecast aktif OEM-A di P1 untuk BRK-A100 (§2), mis. 700 PCS dua minggu lagi. Hari kerja Senin–Sabtu.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → PPIC → **Master Production Schedule** → **Calculate MPS** → P1 → simpan. | Halaman `MPS-P1-<tahun>-0001`, **Draft**, 12 minggu mulai Senin minggu ini. | |
| 2 | Lihat kartu BRK-A100. | FG on hand dipakai sekali (baris Opening balance minggu pertama = on hand − safety stock); Firm orders dan Net forecast per minggu; **Pending QC (not netted)** tampil tapi tidak mengurangi kebutuhan; safety stock kosong tampil **Not configured**. | |
| 3 | Lihat **Planned orders**. | Satu baris per minggu yang kurang; **Due** = hari pertama yang tidak tertutup; **Start** = lead time hari kerja sebelumnya (Sabtu dihitung kerja); **Covers** menyebut SO / forecast yang ditutup. | |
| 4 | Buka **Daily due dates and supply**. | Tanggal harian tiap firm order, net forecast, sisa WO dan QC released. | |
| 5 | **Adjust** satu planned order: qty dinaikkan, Reason "Full die run" → simpan. | "Planned order adjusted"; badge **Adjusted by ppc**, qty hitungan tetap terlihat. | |
| 6 | Hitung MPS baru untuk P1 lagi (daftar → Calculate MPS). | Ditolak: masih ada draft/submitted; selesaikan atau batalkan dulu. | |
| 7 | **Submit for approval**. | Status **Submitted**; tidak ada tombol Adjust. | |
| 8 | Login `ppc` sebagai yang menyiapkan: tidak ada tombol Approve. Login `csa` → buka MPS itu. | Tombol **Return** dan **Approve**. | |
| 9 | Sebelum approve: login `ppc`, ubah safety stock BRK-A100 di Master Data → Planning Parameters. Login `csa` → buka MPS. | Peringatan **Data changed since this calculation**; **Approve** tidak aktif. | |
| 10 | **Return** dengan alasan "Safety stock berubah". Login `ppc` → **Recalculate** → **Submit for approval**. | Kembali Draft dengan alasan; setelah hitung ulang, adjustment hilang (diganti hasil hitung) dan kebutuhan naik sebesar perubahan safety stock. | |
| 11 | Login `csa` → **Approve**. | Status **Approved**. MPS berikutnya yang di-approve membuat yang ini **Superseded**. | |
| 12 | Lihat **Capacity load**. | Tanpa kalender shift: "Capacity not configured", jam beban per work center, tanpa persen. | |
| 13 | Login `aud` → PPIC → Master Production Schedule. | Daftar dan halaman terlihat, tanpa tombol aksi. | |

## 4. MRP dasar (langkah 4, D-124)
Persiapan: `eora:tenants:sync` (izin baru `planning.mrp`). MPS P1 sudah **Approved** dengan planned order (§3). Opsional: Master Data → Planning Parameters → lead time beli untuk COIL-SPCC-12; Materials → Inbound Schedule untuk NUT-M8.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → PPIC → Master Production Schedule → MPS yang Approved → **Run MRP**. | Pindah ke halaman `MRP-P1-<tahun>-0001`. | |
| 2 | Lihat **Planned work orders**. | Planned order FG dari MPS (From: MPS, BOM v…); bila BOM memakai item Semi-Finished: planned WO anak (jatuh tempo = tanggal mulai induk). | |
| 3 | Lihat **Material shortages**. | Per bahan: qty kurang dalam UOM bahan (kg / pcs), **Needed on** = tanggal mulai planned order pemakainya, **Order by** = dikurangi lead time beli (hari kerja Senin–Sabtu); lead time kosong tampil "Not configured". | |
| 4 | Lihat **Per item**. | On hand, untuk WO released (sisa kebutuhan), incoming (inbound), safety stock. Kebutuhan = qty × BOM per pcs (scrap tidak dihitung dua kali). | |
| 5 | Tambah inbound NUT-M8 yang cukup sebelum tanggal butuh → kembali ke MPS → **Run MRP** lagi. | Run baru `…-0002`; NUT-M8 tidak lagi kurang; run lama tetap tersimpan apa adanya. | |
| 6 | Coba Run MRP dari MPS Draft/Submitted. | Tidak ada tombol Run MRP. | |
| 7 | Login `mat` → PPIC → MRP Results. | Daftar run dan halaman run terlihat, tanpa tombol Run MRP. | |

## 5. Planned order → WO (langkah 5, D-125)
Persiapan: MPS P1 Approved dan run MRP terbaru (§4).

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → PPIC → MRP Results → run terbaru. | Di **Planned work orders** ada kotak centang dan tombol **Release to WO** (nonaktif sebelum ada yang dicentang). | |
| 2 | Centang satu atau semua planned order → **Release to WO** → konfirmasi. | Pesan "Work order(s) WO-… drafted"; kolom **Work order** menampilkan nomornya; centangnya tidak bisa dipilih lagi. | |
| 3 | Klik nomor WO. | WO **Draft**, qty dan due date sesuai plan, Reason "From the plan (MRP-…)". | |
| 4 | **Edit** WO itu: ubah qty. | Tersimpan; field item dan reason tidak tampil (tetap dari plan). | |
| 5 | Kembali ke MPS → hitung MPS baru untuk P1 (Calculate MPS). | Minggu yang sudah punya WO tidak direncanakan lagi; WO tampil di "Daily due dates and supply" sebagai supply. | |
| 6 | **Run MRP** lagi dari MPS approved yang sama → coba rilis planned order FG yang sama dari run baru. | Ditolak "Already released". Dari run lama: ditolak "Not the current plan". | |
| 7 | WO: **Submit for release**; login `csa` → **Release**. | Release seperti biasa oleh orang kedua (snapshot BOM/routing). | |
| 8 | Login `mat` → MRP Results → run. | Tanpa kotak centang / tombol Release to WO. | |

## 6. Notice dan inbox persetujuan (D-129)
Berlaku di setiap dokumen yang menunggu orang kedua: routing, BOM, map, net weight, conversion profile, MPS, rilis WO, WO close exception, adjustment, reversal, release QC, tutup/buka periode. Contoh di bawah memakai rilis WO.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → WO Draft → **Ask for release**. | Kotak **Waiting for approval by Company Super Admin** dengan nama penyetuju di plant itu: "Approvers: … They were notified in their inbox." Nama `ppc` sendiri tidak ada. | |
| 2 | Login `csa` → ikon lonceng. | Pesan **Approval requested: WO-…** ("… asks you to approve this work order release."), bertanda perlu tindakan; klik membuka WO itu. | |
| 3 | `csa` → **Release**. | WO Released. | |
| 4 | Login `ppc` → lonceng. | Pesan **Approved: WO-…** dari `csa`. | |
| 5 | Penyetuju lain (mis. `sup`) → lonceng. | Pesan permintaan tadi tidak lagi dihitung "perlu tindakan" (sudah diputuskan orang lain). | |
| 6 | Ulangi 1 lalu `csa` → **Return** dengan alasan. | `ppc` menerima **Returned: WO-…** berisi alasannya. | |
| 7 | Di tenant tanpa penyetuju (role CSA tidak dipegang siapa pun di plant itu), ajukan rilis WO. | Kotak kuning **No one can approve this yet**: minta admin memberi role penyetuju. | |

## 7. Kapasitas MPS dari good pcs per jam (D-130)

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `sup` → Master Data → Routings → buat versi baru untuk item dengan satuan dasar PCS. Edit operasi. Pilih **Standard good output**, isi **600** dan setup **15** menit, lalu simpan. | Operasi menampilkan **600 good pcs/h**. Nol ditolak. |
| 2 | Submit versi baru. Buat atau hitung ulang MPS dengan planned order yang tanggal mulainya berada pada tanggal berlaku versi tersebut. | Selama versi baru menunggu persetujuan, MPS memakai versi routing yang sedang berlaku. |
| 3 | Login `csa` → approve versi. Login `ppc` → hitung ulang MPS. Uji planned order 1.200 PCS dan periksa kontribusi operasi itu ke **Capacity load**. | Beban operasi **2,25 jam**, termasuk 15 menit setup. Yield routing dan parts per cycle tidak diterapkan lagi. |
| 4 | Unduh template Routings. Upload satu operasi dengan **Standard Good Output (pcs/h)** = 600 dan **Cycle seconds** kosong. | Validasi lulus dan commit membuat operasi di versi Draft. Template lama yang hanya memakai Cycle seconds juga bisa divalidasi. |

## 8. WO berantai Semi-Finished (D-131)

1. Siapkan item blank lot-controlled, BOM blank yang memakai bahan baku, dan BOM RH/LH yang memakai item blank yang sama.
2. Buka run pada WO blanking dan post konsumsi bahan terukur serta 650 good blank. Untuk item tanpa inspeksi, pilih lokasi material penerima. Untuk item dengan inspeksi, cek status Pending QC dan lakukan release dari akun QC yang berbeda.
3. Issue 350 dari lot baru ke WO Forming RH, lalu post konsumsi dari lot itu. Pastikan saldo lokasi material tersisa 300 PCS dan WO LH tidak bisa mengambil 301 PCS dari lot tersebut.
4. Dari lot hasil Forming, buka Backward Trace. Expected: hasil Forming → lot blanking → lot bahan baku.

## 9. Plate Grip RH/LH dari satu WO blanking (D-132)

1. Supervisor menyiapkan dua item blank lot-controlled dalam PCS, satu BOM blanking yang memakai sheet, dan satu operasi blanking pada routing utama. Isi **Second output**, **18** pcs per sisi per sheet, dan **550** good PCS per jam gabungan. CSA menyetujui.
2. Jalankan MRP dengan kebutuhan bersih 300 RH dan 350 LH pada minggu yang sama. Harus ada satu planned WO blanking, 20 sheet, 360 PCS standar per sisi, dan 1,309 jam jalan ditambah satu setup. Cek beban blanking di MPS setelah MRP selesai.
3. Rilis WO blanking, issue 20 sheet, dan post satu kali dengan hasil aktual 350 RH good, 340 LH good, dan 10 LH reject. Ledger harus mengurangi 20 sheet sekali dan mencatat dua lot output.
4. Issue 340 good LH ke WO Bending LH. Coba issue lot RH ke WO LH dan pastikan aplikasi menolak. Post hasil Bending dan telusuri kembali ke lot LH hasil blanking dan lot sheet.

## 10. Review desain Material Monitoring dan kepemilikan (D-133)

Status desain interaktif dengan data simulasi. Buka `design/mockups/05-material-monitoring.html` melalui server lokal. Seluruh input pada preview hanya mengubah data sementara di halaman. Lihat doc 35 untuk audit dan usulan kontrak implementasi.

1. Cek urutan kolom Customer, Part Name, Part No., Spec Material, IN RM, OUT ke Produksi, Terpakai, Good, NG, Miss. Bandingkan pilihan Tabel PPIC, Per pemilik, dan Riwayat lot pada desktop dan ponsel.
2. Klik part Bracket Inner. Cek dua WO, lot hasil, lot RM termasuk penerimaan Oktober, sisa material, breakdown Trial/Proses/Final QC, dan status Pending QC/QC Accepted/FG diterima. IN RM membuka lot sumber agar qty penerimaan bersama dihitung sekali.
3. Buka Penerimaan & saldo RM dan filter customer SUP. Material yang layak ditampilkan berasal dari perusahaan dan SUP. Stok aset DPM/SJM/AMI tetap terpisah. Pilih Semua customer untuk mengembalikan daftar lengkap.
4. Buka Kesiapan material WO. Lihat WO yang kekurangan bahan walaupun material customer lain masih tersedia. Stok perusahaan dipakai sekali pada alokasi usulan seluruh WO, kemudian hasilnya difilter untuk tampilan.
5. Klik Simulasi input lalu Final QC. Catat 100 accepted dan 8 NG pada lot pertama. Good berkurang, NG bertambah, dan Miss WO pertama tetap 10 pcs.
6. Simulasikan issue 30 sheet ke WO DPM yang belum diproduksi. Catat hasil 580 Good, 5 NG trial dan 5 NG proses dengan sisa nol. Cek lot FG tanggal produksi contoh dan Miss provisional 10 pcs. Simulasikan penerimaan RM dengan pemilik SJM dan cek prefix lot SJM.
7. Coba pencarian tanpa hasil, reset filter, navigasi keyboard, Escape pada detail, dan Reset simulasi. Refresh mengembalikan data contoh awal. Untuk mengaktifkan transaksi nyata, pilih desain dan review scope ownership/ledger pada doc 35 terlebih dahulu.

## Daily Recovery Planner — D-134
Open PPIC → Production Planning → Daily Recovery Planner with a PPC plant role. Select a target date, customer or part; open the part to inspect lot, WO and today's shift output sources. Use actual authorized records in UAT.

1. With 100 FG and 400 Before Check against 500 outstanding, expect no additional SPK need and Kejar QC. QC reject 20: expect shortage 20. Receipt of the remaining accepted output must leave combined coverage unchanged.
2. Set minimum 500, batch 22: expect proposal 506. Edit Shift I–III with a reason and save. Refresh: values remain.
3. Create SPK Draft: open the linked existing WO workflow. Retry returns the same WO. Subsequent additional planning creates a new plan revision preserving the old link.
4. Change stock/QC after saving: creating from stale inputs must be refused, requiring review/save.
5. Filter customers sharing one part: supply shares must stay stable and never cover two demands. A view-only user cannot edit; another plant/tenant cannot access the plan.
Automated browser fixtures are synthetic; factory UAT and deployment remain separate.

## Material ownership foundation — D-135

Use authorized test records in an isolated plant. Do not convert historical company balances into customer assets.

1. Receive the same lot-controlled material for the company and DPM. Select the customer owner independently of Supplier / sender. Verify separate owner labels and daily RM numbers. Repeating a request must return the same receipt and lot.
2. Create a WO with Production customer DPM. A DPM lot may issue to it. The same lot must be refused for an unassigned or SJM WO. Company material may serve either customer.
3. Record separate production runs per material owner. A posting consuming company material and customer material together must be refused without changing stock. Output, NG and scrap of the accepted posting retain the owner. Customer scrap requires a lot-controlled scrap item.
4. Follow customer output through Pending QC, release and FG receipt. The original lot and owner remain. Delivery may allocate only to the same customer. Reverse through the existing approval workflow and verify unchanged ownership.
5. Split a customer RM lot and inspect parent and child owner. Check stock/receipt export owner columns and tenant/plant access.
6. Temporary limitation: in a plant holding customer-owned physical stock, new aggregate MPS/MRP/Daily Recovery calculations show an actionable owner-aware-planning requirement. Existing saved plan history remains readable; use customer-specific WO while the subsequent planning slice is under development. This foundation is not approved for operational deployment until this boundary is accepted or replaced by owner-aware netting.

## SPK per Line — D-138 (slice 1)

Login sebagai planner (PPC) di plant uji; pastikan line (work center) punya **hours per shift**, mesin aktif, kalender shift yang berlaku, dan minimal satu WO berstatus Released / In production dengan operasi di line itu. Siapkan juga satu user produksi (PRL) di plant yang sama.

1. **Draft:** buka **PPIC → SPK Line → New SPK**, pilih plant, line dan tanggal produksi. Harapan: heading **Draft SPK**, tanpa nomor. Membuat SPK kedua untuk line dan tanggal yang sama ditolak (*SPK already exists*).
2. **Rencana dan beban mesin:** tambah baris (**Work order operation**, **Machine**, **Shift**, **Planned qty**, **Priority**, **Note**) lalu **Save SPK**. Contoh: 600 pcs pada SPH 300 + QC 5 menit = **2.083 h**; **Machine load** menampilkan jam terpakai, kapasitas dan sisa (*left* / *over*), dan shift yang kelebihan disebut terpisah (mis. *S1 1 h over*). Operasi tanpa SPH tampil **Not calculable**. Selama menyimpan, editor terkunci; perubahan yang belum disimpan menampilkan **Unsaved changes** dan tombol **Release to production** nonaktif.
3. **Release:** klik **Release to production**. Harapan: nomor `SPK-{kode perusahaan}-{DDMMYY}-0001` (berurutan per perusahaan per hari). Release ditolak bila SPK kosong, WO-nya sudah tidak Released, mesinnya nonaktif / pindah line, atau shift-nya bukan dari kalender yang berlaku pada tanggal itu.
4. **Notifikasi dan akses produksi:** login sebagai PRL. Harapan: satu notifikasi inbox *SPK released* dengan link ke SPK; **Production → SPK Line** hanya menampilkan SPK Released / Closed plant itu, termasuk kolom **Note**, tanpa tombol ubah (**Revise**, **Close SPK**). Plant / tenant lain tidak bisa melihatnya. Cek juga di ponsel (390 px).
5. **Revisi:** sebagai PPC buka SPK yang sudah released, klik **Revise** dengan alasan. Harapan: draft revisi 2 dengan baris yang masih valid (WO Released, mesin aktif di line; shift dari kalender lama dikosongkan untuk dipilih ulang). SPK lama tetap berlaku sampai revisi di-release; setelah itu revisi memakai nomor yang sama dan SPK lama menjadi **Superseded**. Produksi mendapat notifikasi revisi.
6. **Tutup / batal:** **Close SPK** pada SPK released (hanya PPC dengan hak close) dan **Cancel** pada draft. Teks SPK yang sudah released tidak bisa diubah tanpa revisi. Export list **SPK Line** mengikuti filter yang sama.

SPK slice 1 tidak memposting stok; jumlah aktual tetap dari Production Run dan posting. Generate SPK otomatis, Start run dari SPK dan print PDF ada di slice berikutnya (doc 38 §9).

## Generate SPK — D-139 (slice 2)

Login sebagai planner (PPC) di plant uji tanpa stok milik customer. Siapkan part finished goods dengan sales order firm untuk tanggal uji, BOM dan routing primary yang approved (proses di line yang punya **hours per shift**, mesin preferred di routing dan satu mesin lain di line yang sama), material RM yang cukup untuk sebagian, dan **safety stock** di Plan Parameters.

1. **PPIC → SPK Line → New SPK → Generate SPK**, pilih tanggal, **Generate**. Harapan: **Work orders created and released** berisi WO reason SPK untuk kekurangan Daily Recovery + safety stock (buffer terbesar, bukan dijumlah); di **PPIC → Work Orders** WO itu berstatus Released dengan peminta dan perilis Anda sendiri.
2. **Material short** menampilkan qty kebutuhan, qty yang direncanakan dan kekurangannya; WO hanya sebesar material yang cukup.
3. Buka link draft SPK tiap line. Harapan: WO lama yang jatuh tempo sampai tanggal itu dan WO baru terbagi ke mesin preferred, lalu shift lain, lalu mesin alternatif; proses berikutnya tidak di shift lebih awal; setup hanya sekali per part dan proses di satu mesin.
4. Bila jam tidak cukup: **Proposed overtime** menyebut mesin, shift, jam, WO dan customer, dan *over the plant limit* bila melewati batas OT plant. Beban mesin di SPK menunjukkan shift yang *over*.
5. **Generate** lagi di tanggal yang sama. Harapan: tidak ada WO atau baris baru (*Nothing to plan*).
6. Hapus baris WO hasil generate dari semua SPK lalu **Save SPK** (atau **Cancel** draft-nya). Harapan: WO itu menjadi Cancelled; WO manual tidak pernah dibatalkan atau dirilis oleh Generate.
7. User tanpa hak membuat/mengajukan WO melihat keterangan bahwa Generate butuh role planner WO; plant dengan stok milik customer menolak Generate dengan pesan perencanaan per pemilik.

## Form halaman penuh — D-140

1. Buka list master mana pun (misalnya **Master Data → Work Centers**) lalu klik **New …**. Harapan: form terbuka sebagai halaman (alamat `?new=1`), ada link **Back to …**, field dua kolom di desktop dan satu kolom di ponsel, serta tombol **Cancel / Create …** di bar bawah yang tetap terlihat saat di-scroll.
2. Isi satu field lalu klik **Back to …**. Harapan: muncul **Leave without saving?**. **Discard changes** kembali ke list dengan filter yang sama.
3. Klik **Edit** pada satu baris. Harapan: halaman `?edit=<id>` dengan data record (kode, plant, dll.) di samping form; reload halaman membuka form yang sama.
4. Record yang hanya bisa dilihat (Movement Ledger, lokasi virtual, atau tanpa hak ubah) tetap terbuka di **drawer detail**; aksi baris seperti **Release** / **Split** / **Receive** terbuka sebagai dialog.
5. **PPIC → SPK Line → Generate SPK** membuka halaman sendiri; hasilnya tampil sebagai tabel (Draft SPKs, Work orders created and released, Proposed overtime, Material short, Not planned).

## Calendars — D-142 (doc 40)

Login sebagai PPC (atau CSA) di plant uji.

1. **Master Data → Calendars → New shift version**: kode `STD`, first day hari ini (versi pertama) atau besok, S1 06:00–14:00 break 60 Senin–Kamis, Jumat 06:00–14:30 break 90, Sabtu 07:00–12:15 break 15; S2 14:00–22:00 / 14:30–22:30 / 13:00–18:15. Harapan: tabel minggu menampilkan 14 h Senin–Jumat, 10 h Sabtu, Minggu kosong; versi berstatus *In force* atau *Starts later*.
2. Isi **hours per shift** 7 di work center line. **PPIC → Master Production Schedule**: kapasitas line 80 h per minggu penuh (Sabtu 2 × 5 h, bukan 2 × 7 h).
3. **Working days → New version** mulai besok, production day starts 06:00. Harapan: baris baru *Starts later*; versi lama tetap *In force*.
4. **Add exception day** Sabtu depan, *Non-working day*. Harapan: muncul di tabel; MPS minggu itu kehilangan kapasitas Sabtu. **Edit** jenisnya lalu hapus lagi.
5. Hapus versi yang *Starts later* lewat menu baris → **Remove**. Harapan: dialog menyebut hari pengecualiannya ikut terhapus; versi *In force* tidak punya menu hapus.
6. **SPK Line → New SPK**: pilihan shift hanya shift yang jalan di tanggal itu (Sabtu tanpa S3 dsb.); beban mesin per shift memakai jam kerja shift hari itu.
7. Login sebagai role tanpa `master.calendar.create` (mis. PRL / INV / AUD): halaman Calendars hanya bisa dilihat, tanpa tombol buat / ubah / hapus. Cek juga di ponsel (390 px): tabel minggu bisa digeser di dalam kartu.

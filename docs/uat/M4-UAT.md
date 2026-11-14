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
   Akun serba bisa (semua role, D-112) — sekali saja, pakai password demo Anda:
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:demo:uat-account --password='<password demo>'
   ```
   `uat@demo-stamping.test` bisa membuka semua modul, setting dan approval, kecuali meng-approve pengajuannya sendiri (aturan orang kedua, D-060) — approval itu tetap oleh `csa`.
3. Web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
4. Login pakai password demo. Akun: `ppc@demo-stamping.test` (planner: membuat dan mengonfirmasi SO, membuat WO), `csa@demo-stamping.test` (admin: me-release WO; juga menyetujui BOM/routing dan reversal), `fgw@demo-stamping.test` (gudang FG: hanya melihat SO), `mat@demo-stamping.test` (gudang bahan: terima, issue, terima return), `prl@demo-stamping.test` (production leader: minta return), `inv@demo-stamping.test` (inventory control: minta reversal).

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
Persiapan: BOM dan routing BRK-A100 dari data demo masih **Draft**. Sebagai `ppc` ajukan (Master Data → BOMs / Routings → Submit), lalu sebagai `csa` setujui keduanya. Di tenant demo yang baru di-seed, draf hasil import disiapkan oleh `csa` sendiri — `csa` tidak boleh menyetujuinya (D-060); setujui dengan akun `uat` (§0).

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

## 3. Issue dan return bahan (langkah 3, D-110)
Persiapan: WO dari §2 langkah 6 masih **Released** (jangan pakai WO yang dibatalkan di §2 langkah 8). Sebagai `mat` terima coil dan nut BRK-A100 lewat Materials → **RM Receipts** (coil yang wajib incoming release di-release dulu oleh `qci`).

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `mat` → Materials → **Material Issue** → **Issue material**. | Halaman Issue material; pilihan Released work order hanya WO yang Released. | |
| 2 | Pilih WO itu. | To work center terisi work center operasi pertama routing; tabel Materials berisi coil dan nut dengan Required, Issued 0, At production 0. | |
| 3 | **Add … lot** pada coil → pilih rak dan lot, quantity lebih dari yang tersedia → **Issue material**. | Ditolak di halaman: "only … is available at …". | |
| 4 | Quantity lebih besar dari Required tapi masih tersedia (mis. satu coil utuh), tambah satu baris nut → **Issue material** → konfirmasi. | Pesan "ISS-P1-<tahun>-0001 issued to <work center>"; over-issue diterima. | |
| 5 | Inventory → **Material at Production**. | Coil dan nut per work center, WO dan lot dengan quantity yang di-issue; tanpa kata WIP. Stock Overview: rak RM berkurang, lokasi MAP-… bertambah. | |
| 6 | PPIC → Work Orders → buka WO itu. | Kolom Issued dan At production terisi; tombol **Issue material** ada untuk `mat`. | |
| 7 | Login `prl` → Materials → **Material Return** → **Ask for a return** → pilih baris coil dari Material at Production, quantity lebih dari yang ada. | Ditolak: "… has … of it at …". | |
| 8 | Quantity `15`, note "Sisa coil" → **Ask for return** → konfirmasi. | Status **Submitted** (menunggu diterima); stok belum berubah. Tidak ada tombol Receive untuk `prl`. | |
| 9 | Login `mat` → buka return itu → **Receive** → rak RM, quantity `14.5` → **Receive** → konfirmasi. | Nomor `RTN-P1-<tahun>-0001`; Asked 15, Received 14.5 ke rak itu, Difference −0.5. Material at Production coil berkurang 14.5. | |
| 10 | `prl` minta return kedua; `mat` → **Refuse** dengan alasan. | Status **Rejected** dengan alasan; stok tidak berubah. | |
| 11 | Login `inv` → Inventory → **Reversals** → minta reversal atas dokumen ISS dari langkah 4; login `csa` → setujui. | Ditolak: material sudah sebagian dikembalikan — reverse return-nya dulu (REVERSAL_BLOCKED_BY_DOWNSTREAM). Tolak permintaan itu dengan alasan. | |

## 4. Posting produksi (langkah 4, D-111)
Persiapan: WO dari §3 sudah punya coil dan nut di work center (Material at Production). Lokasi QC area, NG area dan scrap yard plant P1 ada di Master Data → Locations; item SCRAP-SPCC ada.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `prl` → PPIC → Work Orders → buka WO itu → **Open run**. | Halaman Open run; work center operasi pertama terpilih. | |
| 2 | **Open run**. | Halaman `RUN-P1-<tahun>-0001`, status **Open**; tabel "Material at the work center" berisi coil dan nut WO itu; WO jadi **In production**. | |
| 3 | **Post production** tanpa isi apa pun. | Pesan "Enter what was consumed: at least one material line, measured." — tidak ada yang terkirim. | |
| 4 | **Add consumed material** → coil, "Weighed what is left", Left lebih besar dari yang ada. | Ditolak: "what is left must be less than … here". | |
| 5 | Left = sisa yang ditimbang (mis. 15), Evidence "Scale S-01"; tambah nut "Counted what is left" 0; Good 950, NG rework 20, NG reject 10; **Add scrap** SCRAP-SPCC 12 KG reason Skeleton → **Post production** → konfirmasi. | Pesan "PRD-P1-<tahun>-0001 posted, output lot L…"; tabel material: coil tinggal 15 KG, nut hilang; Postings: Good 950, NG 30, Scrap 12 KG. | |
| 6 | Inventory → Stock Overview → BRK-A100. | QC area **Pending QC** 950 (BRK-A100 wajib inspeksi); NG area Rework 20 dan Rejected 10; SCRAP-SPCC 12 KG di scrap yard. | |
| 7 | Post lagi untuk run yang sama di shift yang sama. | Ditolak: "This run already has its regular posting for this production date and shift…". | |
| 8 | Login `inv` → Reversals → minta reversal PRD itu; `csa` setujui. Lalu `prl` → run → post lagi dengan **Corrects** = PRD yang di-reverse. | Reversal mengembalikan material ke work center dan menghapus output; posting koreksi diterima dan menyebut posting yang dikoreksi. | |
| 9 | `prl` → **Close run** → konfirmasi. | Status **Closed**; form posting hilang; sisa coil tetap di work center untuk WO itu. | |
| 10 | Buka run baru tanpa posting → **Cancel run** dengan alasan. | Status **Cancelled** dengan alasan. | |

## 5. QC disposition (langkah 5, D-113)
Persiapan: §4 langkah 5 sudah memposting output BRK-A100 (950 **Pending QC** di QC area). Akun `qci` (inspektur) dan `qcs` (supervisor QC).

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `qci` → Quality → **Pending QC Queue**. | Baris BRK-A100 lot L… **Pending QC** 950 PCS di QC area; juga NG area Rejected dari §4. | |
| 2 | Buka baris Pending QC → **Post decision** tanpa isi. | Pesan "Enter the quantity of at least one decision." — tidak ada yang terkirim. | |
| 3 | Release 900, Hold 20 tanpa reason → **Post decision**. | Ditolak: "Hold: choose the reason." | |
| 4 | Hold reason **Dimension out of tolerance**, note "Hole 0.2 mm off"; Reject 10 reason **Crack** → **Post decision** → konfirmasi. | Pesan "QCD-P1-<tahun>-0001 posted"; sisa 20 PCS tetap Pending QC. | |
| 5 | Inventory → Stock Overview → BRK-A100. | QC area: QC released 900, Hold 20, Pending QC 20; NG area Rejected bertambah 10. | |
| 6 | Bila output §4 diposting dengan akun `uat` (punya semua peran): login `uat` → coba putuskan lot itu. | Ditolak (SoD-01): "You reported this lot’s output; another person decides its quality." — pelapor output tidak memutuskan kualitasnya. | |
| 7 | `qci` → buka baris **Hold** 20. | Hanya **Reject** yang bisa diputuskan; kartu **Ask a release out of policy**. | |
| 8 | Release 20, Reason "Customer accepted the deviation" → **Ask release** → konfirmasi. | Pesan "Release asked; it waits for a second person"; stok Hold tetap 20. | |
| 9 | `qci` → Quality → **Dispositions** → buka request itu. | Status **Submitted**; tidak ada tombol Approve untuk yang meminta. | |
| 10 | Login `qcs` → Dispositions → buka request → **Approve**. | Judul jadi `QCD-P1-<tahun>-0002`, **Posted**; Stock: Hold 0, QC released 920. | |
| 11 | `qci` minta release 2 dari NG area **Rejected**; `qcs` → **Reject** dengan alasan. | Status **Rejected** dengan alasan; stok Rejected tidak berubah. | |
| 12 | Login `inv` → Inventory → Reversals → minta reversal `QCD-P1-<tahun>-0001`; login `csa` → buka request itu. | Tombol Approve tidak ada untuk `csa` (QC disposition diputuskan quality). | |
| 13 | Login `qcs` → Quality → Dispositions → buka `QCD-P1-<tahun>-0001`. | Ada "Reversal asked" + alasan; **Approve** → disposisi jadi **Reversed**, stok kembali seperti sebelum keputusan. | |

## 6. FG receipt (langkah 6, D-114)
Persiapan: §5 sudah me-release BRK-A100 (QC released di QC area). Lokasi FG (mis. FG-A01) aktif di Master Data → Locations. Akun `fgw`.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `fgw` → Warehouse → **FG Receipt** → **Receive FG**. | FG location terisi rak FG pertama; daftar "QC released at P1" berisi BRK-A100 per lot dengan qty released. | |
| 2 | **Receive FG** tanpa isi. | Pesan "Enter the counted quantity of at least one lot." — tidak ada yang terkirim. | |
| 3 | Isi qty lebih besar dari yang released. | Ditolak: "only … PCS is QC released." | |
| 4 | Isi qty hitungan (kurang dari released), Note "Shift 1" → **Receive FG** → konfirmasi. | Pesan "FGR-P1-<tahun>-0001 received into FG-A01"; baris lot itu tinggal sisanya. | |
| 5 | Inventory → Stock Overview → BRK-A100. | FG-A01 **Unrestricted** = qty diterima; QC area QC released = sisanya. | |
| 6 | Warehouse → FG Receipt (daftar) → buka FGR itu. | Detail: rak, lot dan qty, oleh `fgw`, status Posted. | |
| 7 | Login `qci` → Warehouse → FG Receipt → **Receive FG**. | Daftar bisa dilihat, tapi halaman Receive FG menampilkan "Receiving FG is not available for your roles". | |
| 8 | Login `inv` → Reversals → minta reversal FGR itu; `csa` setujui. | Stok kembali ke QC area QC released; FGR jadi Reversed. | |

## 7a. Alokasi dan pick (langkah 7a, D-115)
Persiapan: §6 sudah menerima BRK-A100 ke rak FG. Akun `ppc` membuat dan mengkonfirmasi SO BRK-A100 (mis. 300 PCS, jatuh tempo beberapa hari lagi). Lokasi STAGING (mis. STG-01) aktif. Akun `dlv` dan `fgw`.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `dlv` → Delivery → **Allocation & Pick**. | Baris SO itu: Required 300, Allocated 0, Staged 0, To allocate 300. | |
| 2 | Buka baris itu. | Kartu **Allocate** berisi usulan FIFO (lot tertua dulu) terisi sampai 300. | |
| 3 | Ubah qty satu baris melebihi yang available → **Allocate**. | Ditolak: "only … PCS is available there." | |
| 4 | Kembalikan ke usulan → **Allocate** → konfirmasi. | Pesan "Allocated"; Allocated 300, To allocate 0; Stock Overview: on-hand tetap, available turun 300. | |
| 5 | Login `ppc` → SO itu → coba batalkan schedule line. | Ditolak: FG masih dialokasikan. | |
| 6 | Login `fgw` → buka baris yang sama. | Tidak ada tombol Release; Pick to = STG-01. | |
| 7 | Pick 200 → **Pick to staging** → konfirmasi. | Pesan "PCK-P1-<tahun>-0001 picked to STG-01"; Allocated 100, Staged 200. | |
| 8 | Staged: Put back 50 ke rak FG → konfirmasi. | Staged 150; 50 PCS kembali ke rak FG tanpa alokasi; To allocate 50. | |
| 9 | Login `dlv` → **Release** sisa alokasi dengan alasan. | Allocated 0; To allocate 150. | |

## 7b. Shipment (langkah 7b, D-116)
Persiapan: §7a sudah men-stage barang untuk SO itu (mis. 150 PCS di STG-01). Akun `fgw` atau `dlv`; `inv` dan `csa` untuk reversal.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `fgw` → Delivery → **Shipments** → **Ship** → pilih customer. | "Staged for <customer>" berisi baris SO itu dengan qty staged terisi penuh dan sisa yang harus dikirim. | |
| 2 | Isi qty lebih besar dari staged → **Ship**. | Ditolak: "only … PCS is staged for it." | |
| 3 | Qty 100, No. kendaraan "B 1234 XY", Sopir "Budi" → **Ship** → konfirmasi. | Pesan "SHP-P1-<tahun>-0001 shipped"; baris itu tinggal 50 staged. | |
| 4 | Shipments (daftar) → buka shipment itu. | Customer, kendaraan, sopir, barang terkirim, status Posted. | |
| 5 | Login `ppc` → SO itu. | Schedule line: Delivered 100, Outstanding sisa, status **Partially fulfilled**. | |
| 6 | Kirim sisa sampai penuh. | Status schedule line **Fulfilled**; baris hilang dari Allocation & Pick. | |
| 7 | Login `inv` → Reversals → minta reversal shipment terakhir; `csa` setujui. | Barang kembali ke STAGING untuk schedule line itu; Delivered turun; status kembali **Partially fulfilled**. | |

## 8. Penutupan WO (langkah 8, D-117)
Persiapan: WO dari §3–4 masih In production dengan sisa coil di work center. Jalankan `eora:tenants:sync` setelah migrate (izin baru `planning.wo_close_exception`). Akun `ppc`, `prl`, `csa`.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → PPIC → Work Orders → buka WO itu. | Tombol **Close work order** nonaktif; kartu **Closing** menyebut run masih open dan/atau material masih di produksi, plus daftar "Left at production". | |
| 2 | Login `prl` → tutup run-nya. Kembali sebagai `ppc`. | Closing tinggal menyebut material di produksi. | |
| 3 | **Ask close exception** → alasan "Coil end too short" → konfirmasi. | Pesan "Close exception asked…"; panel Approval menunggu orang kedua; tombol Ask hilang. | |
| 4 | Login `csa` → PPIC → **WO Close Exceptions**. | Baris exception itu (Waiting for approval) dengan material yang akan dibuang. | |
| 5 | Buka → **Approve**. | Material pindah ke scrap yard (WCX-P1-<tahun>-0001); "Nothing keeps it open". | |
| 6 | Login `ppc` → **Close work order** → konfirmasi. | Status **Closed**; tombol Issue/Open run hilang. | |
| 7 | Login `mat` → coba issue bahan ke WO itu. | Ditolak: WO bukan Released. | |

## 9. Trace dan pesanan demo (langkah 9, D-118)
Persiapan: jalankan `eora:tenants:sync` setelah migrate (izin baru `traceability.lot_trace.view`). Satu pesanan penuh siap dengan perintah berikut (aman diulang; butuh master demo, stok awal coil HEAT-A102 released dan nut di RM-A01):
```bash
docker compose run --rm --entrypoint php db-migrate artisan eora:demo:m4
```
Di tenant demo yang baru di-seed, BOM dan routing BRK-A100 hasil import disiapkan oleh `csa`, jadi `csa` tidak boleh menyetujuinya sendiri (D-060): buat dulu akun `uat` (§0) — perintah di atas memakainya sebagai approver kedua. Hasil akhirnya mencetak nomor SO, WO, lot FG dan SHP.

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Login `ppc` → Traceability. | Menu Backward/Forward Trace tidak ada (hanya CSA, INV, AUD). | |
| 2 | Login `inv` → Traceability → **Backward Trace** → isi nomor SHP dari perintah di atas → **Trace**. | "What SHP-… was made of": lot FG BRK-A100 (**Made**) dengan baris "Shipped SHP-… to OEM-A · SO-… 1/1 · customer PO PO-DEMO-M4 · 150 PCS · <oleh>, <waktu>". | |
| 3 | Lihat baris di bawahnya. | Lot coil COIL-SPCC-12 dan nut NUT-M8 (**Received**): "… KG used for 300 PCS · Production posting PRD-… · run RUN-… · WO-… · <oleh>, <waktu>" dan "Received on RCV-… from SUP-STEEL · supplier lot HEAT-A102 · <oleh>, <waktu>". | |
| 4 | **Forward trace** → isi nomor lot coil → **Trace**. | Bila nomor lot sama dipakai beberapa item: "… is the number of several lots" → pilih COIL-SPCC-12. "Where … went": lot FG yang dibuat dari coil itu dan shipment ke OEM-A (untuk recall). URL menyimpan pencarian (bisa dibagikan). | |
| 5 | Backward Trace → nomor yang tidak ada (mis. `L9999-9999`). | "Nothing with this number in your plants. Check the lot or shipment number." | |
| 6 | Forward Trace → nomor SHP. | Ditolak: "A shipment is traced backward. Trace one of its lots forward instead." | |
| 7 | Login `ppc` → Demand → Sales Orders → SO dari perintah di atas. | Schedule line Delivered 150 dari 300, **Partially fulfilled**. | |
| 8 | Login `inv` → reversal shipment itu, `csa` setujui; trace lagi. | Shipment tetap tampil, ditandai **Reversed**; SO itu kembali Delivered 0. | |


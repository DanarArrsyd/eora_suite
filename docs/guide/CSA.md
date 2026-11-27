# Company Super Admin / Approver (CSA)

Penyetuju utama dan admin perusahaan: menyetujui pekerjaan sensitif dan mengelola master data serta user.

## Menu Anda

**Demand**

- **Sales Orders** — Sales Order dari PO customer beserta jadwal kirimnya; revisi jadwal kirim.
- **Forecast** — Perkiraan kebutuhan per part customer per minggu; versi aktif dipakai MPS.

**PPIC / Planning**

- **Master Production Schedule** — Rencana produksi per minggu (12 minggu); dihitung, disubmit, disetujui.
- **MRP Results** — Hasil MRP: planned work order dan daftar kekurangan bahan; rilis planned order ke WO.
- **Work Orders** — Work order: Draft, minta rilis, rilis (orang kedua), tutup.
- **WO Close Exceptions** — Sisa bahan di produksi yang dibuang ke scrap agar WO bisa ditutup.

**Materials**

- **Inbound Schedule** — Jadwal kedatangan bahan dari supplier.
- **RM Receipts** — Penerimaan bahan baku dari supplier.
- **Material Issue** — Pengeluaran bahan ke work center untuk WO yang sudah dirilis.
- **Material Return** — Pengembalian sisa bahan dari produksi ke gudang.
- **Material Lots** — Lot bahan per lokasi dan status; split lot (sisa / potongan).

**Production**

- **Production Runs** — Membuka, mencatat, dan menutup run produksi per WO dan work center.
- **Production Postings** — Riwayat posting produksi (konsumsi, hasil, scrap).

**Quality**

- **Pending QC Queue** — Hasil produksi yang menunggu keputusan QC.
- **Dispositions** — Keputusan QC (release, hold, rework, reject) dan release di luar kebijakan.

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Material at Production** — Bahan yang ada di work center dan belum dipakai / dikembalikan.
- **Movement Ledger** — Semua pergerakan stok, terbaru di atas.
- **Adjustments** — Koreksi stok dengan alasan, disetujui orang kedua.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.
- **Period Close** — Tutup / buka kembali periode stok bulanan.

**Warehouse**

- **FG Receipt** — Penerimaan barang jadi (sudah QC release) ke rak FG.

**Delivery**

- **Allocation & Pick** — Alokasi FG ke jadwal kirim dan pick ke staging.
- **Shipments** — Pengiriman barang yang sudah di staging ke customer.

**Traceability**

- **Backward Trace** — Telusur mundur: lot / pengiriman → bahan asal.
- **Forward Trace** — Telusur maju: lot bahan → produk dan pengiriman.

**Master Data**

- **Plants & Warehouses** — Plant, gudang, dan lokasi.
- **Work Centers** — Lini / cell produksi, mesin, dies.
- **Items** — Part dan material.
- **Units & Conversions** — Satuan dan konversi satuan per item.
- **Customers** — Data customer.
- **Customer Part Mapping** — Nomor part customer → item internal.
- **Suppliers** — Data supplier.
- **Process Types** — Jenis proses operasi (blanking, bending, inspeksi, …).
- **Routings** — Urutan operasi per item dan plant (berversi, disetujui orang kedua).
- **BOMs** — Kebutuhan bahan per pcs (berversi, disetujui orang kedua).
- **Conversion Data** — Berat per pcs dan konversi coil / sheet → pcs (berversi).
- **Planning Parameters** — Lead time dan safety stock per item dan plant.
- **Import** — Impor master data dari template Excel.
- **Exports** — File Excel hasil ekspor Anda (24 jam).

**Administration**

- **Company** — Data perusahaan.
- **Users** — Undang user dan beri role.
- **Roles & Permissions** — Role dan siapa yang memegangnya.

## Langkah kerja

1. PPIC → Master Production Schedule → buka MPS **Submitted** → periksa → **Approve** atau **Return** dengan alasan.
2. PPIC → Work Orders → WO **Waiting for release** → **Release** (BOM dan routing disalin) atau **Return**.
3. Inventory → Adjustments / Reversals / Period Close → setujui permintaan yang menunggu.
4. Master Data → Routings / BOMs / Conversion Data → setujui versi yang disubmit Supervisor.
5. Master Data → **Calendars** → atur jam shift dan hari kerja plant (D-142, lihat panduan PPC bagian *Calendars*). Ini pengecualian dari aturan di bawah: CSA dan PPC sama-sama boleh mengubah kalender.

Master data diubah oleh **Supervisor** (D-128); CSA menyetujui, tidak menginput master.

## Persetujuan

- Tidak bisa menyetujui yang Anda buat sendiri.

Kembali ke [Panduan Pengguna](README.md).

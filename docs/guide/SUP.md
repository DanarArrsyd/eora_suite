# Supervisor (SUP)

Akun pemilik / kepala operasional. Melihat semua menu yang sudah jalan dan bisa menginput apa saja: planning, produksi, gudang, kualitas, dan user. **Satu-satunya pengubah master data** (D-128): item, satuan, customer, part customer, supplier, gudang/lokasi, work center, mesin, dies, process type, routing, BOM, conversion data, dan import Excel.

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
- **Incoming Release** — Pelepasan bahan yang menunggu inspeksi masuk.
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

1. Master data: tambah / ubah / nonaktifkan item, customer, supplier, dan master lain di menu Master Data, atau lewat **Import** template Excel (urutan: Units → Customers → Items → Material specs → Unit conversions → Customer parts → Process types → Routings → BOMs).
2. Routing, BOM, dan conversion data yang Anda siapkan → **Submit** → disetujui **CSA**.
3. Kerjakan tugas role mana pun dari menu yang sesuai (lihat panduan role terkait).
4. Setujui pekerjaan orang lain: MPS, rilis WO, koreksi stok, reversal, tutup buku, routing / BOM, conversion data.
5. Kelola user: Administration → Users → undang, lalu beri role.

## Persetujuan

- Anda **tidak bisa** menyetujui atau merilis pekerjaan yang Anda buat sendiri (routing / BOM / conversion data yang Anda siapkan, MPS yang Anda hitung, WO yang Anda minta rilis, koreksi yang Anda ajukan). Penyetujunya **CSA**.
- Data master tidak dihapus permanen: item, customer, supplier **dinonaktifkan**; routing, BOM, conversion data diganti **versi baru**; draft yang belum dipakai bisa dibatalkan.

Kembali ke [Panduan Pengguna](README.md).

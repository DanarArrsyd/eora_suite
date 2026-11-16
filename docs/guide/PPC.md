# PPIC Planner (PPC)

Merencanakan produksi: order customer, forecast, MPS, MRP, dan work order.

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

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

**Master Data**

- **Items** — Part dan material.
- **Units & Conversions** — Satuan dan konversi satuan per item.
- **Customers** — Data customer.
- **Customer Part Mapping** — Nomor part customer → item internal.
- **Suppliers** — Data supplier.
- **Process Types** — Jenis proses operasi (blanking, bending, inspeksi, …).
- **Routings** — Urutan operasi per item dan plant (berversi, disetujui orang kedua).
- **BOMs** — Kebutuhan bahan per pcs (berversi, disetujui orang kedua).
- **Planning Parameters** — Lead time dan safety stock per item dan plant.
- **Import** — Impor master data dari template Excel.
- **Exports** — File Excel hasil ekspor Anda (24 jam).

## Langkah kerja

1. Demand → Sales Orders → catat PO customer (line, jadwal kirim) → **Confirm**. Ubah jadwal Firm lewat **Revise** dengan alasan.
2. Demand → Forecast → versi baru per customer → isi qty per minggu → **Activate**.
3. Master Data → Planning Parameters → lead time dan safety stock per item.
4. PPIC → Master Production Schedule → **Calculate MPS** → periksa planned order (boleh **Adjust** dengan alasan) → **Submit for approval**.
5. Setelah MPS Approved: **Run MRP** → PPIC → MRP Results → lihat kekurangan bahan → centang planned order → **Release to WO**.
6. PPIC → Work Orders → buka WO Draft → periksa → **Ask for release**. Untuk WO di luar plan: **New work order** dengan alasan.

## Persetujuan

- MPS dan rilis WO disetujui CSA. Routing / BOM yang Anda buat disetujui CSA.

Kembali ke [Panduan Pengguna](README.md).

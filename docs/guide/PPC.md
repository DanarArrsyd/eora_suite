# PPIC Planner (PPC)

Merencanakan produksi dari order dan forecast yang dicatat Delivery: MPS, MRP, dan work order.

## Menu Anda

**Demand**

- **Sales Orders** — Order customer dan jadwal kirimnya (hanya lihat; dicatat Delivery).
- **Forecast** — Forecast per part customer per minggu (hanya lihat; diaktifkan Delivery).

**PPIC / Planning**

- **Master Production Schedule** — Rencana produksi per minggu (12 minggu); dihitung, disubmit, disetujui.
- **MRP Results** — Hasil MRP: planned work order dan daftar kekurangan bahan; rilis planned order ke WO.
- **Work Orders** — Work order: Draft, minta rilis, rilis (orang kedua), tutup.
- **WO Close Exceptions** — Sisa bahan di produksi yang dibuang ke scrap agar WO bisa ditutup.

**Materials**

- **Inbound Schedule** — Jadwal kedatangan bahan dari supplier.

**Quality**

- **Pending QC Queue** — Barang hasil produksi yang masih menunggu QC (hanya lihat). Belum dihitung sebagai stok tersedia.

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

**Master Data**

- **Planning Parameters** — Lead time dan safety stock per item dan plant.

Item, customer, routing, BOM, dan master lain diubah oleh **Supervisor** (D-128). Bila ada part, BOM, atau routing baru / berubah, sampaikan ke Supervisor.

## Langkah kerja

1. Demand → Sales Orders dan Forecast → periksa order Confirmed dan forecast Active yang dicatat Delivery.
2. Quality → Pending QC Queue → lihat barang yang masih di QC sebelum menambah produksi.
3. Master Data → Planning Parameters → lead time dan safety stock per item.
4. PPIC → Master Production Schedule → **Calculate MPS** → periksa planned order (boleh **Adjust** dengan alasan) → **Submit for approval**.
5. Setelah MPS Approved: **Run MRP** → PPIC → MRP Results → lihat kekurangan bahan → centang planned order → **Release to WO**.
6. PPIC → Work Orders → buka WO Draft → periksa → **Ask for release**. Untuk WO di luar plan: **New work order** dengan alasan.

## Persetujuan

- MPS dan rilis WO disetujui CSA.

Kembali ke [Panduan Pengguna](README.md).

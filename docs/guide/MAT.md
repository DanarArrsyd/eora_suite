# Materials PIC (MAT)

Mengurus bahan baku: kedatangan, penerimaan, pengeluaran ke produksi, dan pengembalian.

## Menu Anda

**PPIC / Planning**

- **MRP Results** — Hasil MRP: planned work order dan daftar kekurangan bahan; rilis planned order ke WO.
- **Work Orders** — Work order: Draft, minta rilis, rilis (orang kedua), tutup.

**Materials**

- **Inbound Schedule** — Jadwal kedatangan bahan dari supplier.
- **RM Receipts** — Penerimaan bahan baku dari supplier.
- **Material Issue** — Pengeluaran bahan ke work center untuk WO yang sudah dirilis.
- **Material Return** — Pengembalian sisa bahan dari produksi ke gudang.
- **Material Lots** — Lot bahan per lokasi dan status; split lot (sisa / potongan).

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Material at Production** — Bahan yang ada di work center dan belum dipakai / dikembalikan.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

Master data (item, supplier, satuan) diubah oleh **Supervisor** (D-128); minta ke Supervisor bila ada bahan atau supplier baru.

## Langkah kerja

1. Materials → Inbound Schedule → catat jadwal kedatangan dari supplier.
2. Materials → RM Receipts → terima bahan (lot supplier, qty); bahan yang perlu inspeksi menunggu Incoming Release.
3. PPIC → MRP Results → lihat **Material shortages** (tanggal butuh, tanggal harus pesan).
4. Materials → Material Issue → keluarkan bahan untuk WO yang sudah dirilis ke work center-nya.
5. Materials → Material Return → terima sisa bahan yang dikembalikan produksi (timbang / hitung).

## Persetujuan

- Pembatalan dokumen yang salah lewat Inventory → Reversals, disetujui INV / CSA.

Kembali ke [Panduan Pengguna](README.md).

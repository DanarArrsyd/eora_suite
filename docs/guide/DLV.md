# Delivery (DLV)

Mencatat permintaan customer (Sales Order, PO, kanban/jadwal kirim, forecast) dan mengatur pengiriman ke customer.

## Menu Anda

**Demand**

- **Sales Orders** — Sales Order dari PO customer beserta jadwal kirimnya (kanban / delivery schedule); revisi jadwal kirim.
- **Forecast** — Perkiraan kebutuhan per part customer per minggu; versi aktif dipakai MPS.

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

**Delivery**

- **Allocation & Pick** — Alokasi FG ke jadwal kirim dan pick ke staging.
- **Shipments** — Pengiriman barang yang sudah di staging ke customer.

## Langkah kerja

1. Demand → Sales Orders → **New order** → pilih customer dan plant → tambah line (part customer, nomor PO dan line PO, jadwal kirim per tanggal) → **Confirm**. Jadwal yang sudah Firm diubah lewat **Revise** dengan alasan; dibatalkan dengan alasan.
2. Demand → Forecast → versi baru per customer → isi qty per minggu → **Activate**. Versi aktif langsung dipakai planner di MPS.
3. Delivery → Allocation & Pick → alokasikan FG ke jadwal kirim (lot terlama dulu).
4. Setelah FGW pick ke staging: Delivery → Shipments → **New shipment** → kirim per customer (boleh sebagian).
5. Inventory → Stock Overview → cek stok FG yang tersedia.

## Persetujuan

- Sales Order dan forecast tidak perlu penyetuju kedua: Anda sendiri yang confirm / activate.
- Shipment yang salah dibetulkan lewat Reversals, disetujui INV / CSA.

Bila Anda juga memegang FG Warehouse (FGW), menu Anda adalah gabungan keduanya.

Kembali ke [Panduan Pengguna](README.md).

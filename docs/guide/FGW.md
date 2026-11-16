# FG Warehouse (FGW)

Mengurus gudang barang jadi: menerima FG dan menyiapkan pengiriman.

## Menu Anda

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

**Warehouse**

- **FG Receipt** — Penerimaan barang jadi (sudah QC release) ke rak FG.

**Delivery**

- **Allocation & Pick** — Alokasi FG ke jadwal kirim dan pick ke staging.

## Langkah kerja

1. Warehouse → FG Receipt → terima barang yang sudah QC release dari area QC ke rak FG (qty yang dihitung).
2. Delivery → Allocation & Pick → pick barang yang sudah dialokasikan ke staging.
3. Inventory → Stock Overview → cek stok FG.

## Persetujuan

- Koreksi dokumen lewat Reversals, disetujui INV / CSA.

Kembali ke [Panduan Pengguna](README.md).

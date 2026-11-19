# Production Leader (PRL)

Memimpin produksi di lini: membuka run, mencatat hasil dan konsumsi, menutup run.

## Menu Anda

**PPIC / Planning**

- **Work Orders** — Work order: Draft, minta rilis, rilis (orang kedua), tutup.

**Materials**

- **Material Return** — Pengembalian sisa bahan dari produksi ke gudang.
- **Material Lots** — Lot bahan per lokasi dan status; split lot (sisa / potongan).

**Production**

- **SPK Line** — SPK per line per hari yang dirilis PPIC: WO, proses, mesin, qty, prioritas, dan beban jam per mesin (hanya lihat).
- **Production Runs** — Membuka, mencatat, dan menutup run produksi per WO dan work center.
- **Production Postings** — Riwayat posting produksi (konsumsi, hasil, scrap).

**Inventory**

- **Stock Overview** — Stok per lokasi, lot, dan status.
- **Material at Production** — Bahan yang ada di work center dan belum dipakai / dikembalikan.
- **Reversals** — Membatalkan dokumen yang sudah diposting, disetujui orang kedua.

## Langkah kerja

1. Production → **SPK Line** → buka SPK line Anda untuk hari ini (notifikasi **SPK released** di lonceng membuka SPK-nya). Kerjakan baris sesuai **Priority**; bila SPK direvisi, notifikasi menyebut revisi barunya.
2. Production → Production Runs → **Open run** untuk WO di work center (bahan harus sudah di-issue).
3. Di run: **Post** hasil per tanggal produksi dan shift — good, NG rework, NG reject, scrap, dan sisa bahan (ditimbang / dihitung).
4. Materials → Material Return → minta pengembalian sisa bahan ke gudang.
5. Tutup run setelah selesai.

## Persetujuan

- Posting yang salah dibetulkan lewat reversal, disetujui INV / CSA.

Kembali ke [Panduan Pengguna](README.md).

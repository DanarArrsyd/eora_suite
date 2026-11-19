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
   - Di **Capacity load**, operasi dengan **Standard Good Output** memakai target good dibagi rate per jam, lalu ditambah setup. Contoh 1.200 good pcs pada 600 pcs/jam dengan setup 15 menit membebani work center selama 2,25 jam.
5. Setelah MPS Approved: **Run MRP** → PPIC → MRP Results → lihat kekurangan bahan → centang planned order → **Release to WO**.
6. PPIC → Work Orders → buka WO Draft → periksa → **Ask for release**. Untuk WO di luar plan: **New work order** dengan alasan.
   - Isi **Production customer** bila WO memakai bahan milik customer. Bahan customer hanya bisa dipakai untuk WO customer itu. Bila WO untuk delivery, customer-nya harus sama.

## Persetujuan

- MPS dan rilis WO disetujui CSA.

Kembali ke [Panduan Pengguna](README.md).

## Daily Recovery Planner (D-134)

1. Buka **PPIC → Daily Recovery Planner**, pilih tanggal target di kiri toolbar.
2. Tiap baris: kebutuhan kirim customer per part (**Demand to target**) dan **Next step** (mis. Plan needed, Waiting for QC, Material short). Kolom **Supply covering the demand** berisi **FG stock** dan **Pending QC** (Before Check); **QC released** dan **Remaining WO** bisa ditampilkan lewat tombol kolom. Kolom **Work order plan** berisi **Net need** dan **Proposed**; **Material capacity** dan **Planned** juga lewat tombol kolom. Klik nama part (atau **Review**) untuk melihat lot, WO sumber dan hasil produksi hari ini.
   - Filter **Next step** untuk melihat yang perlu dikerjakan saja, misalnya *Plan needed*. Klik judul kolom untuk mengurutkan.
3. Isi **Minimum work order (pcs)** (default 500), **Pcs per material batch**, **Buffer (pcs)** dan **Shift I–III plan (pcs)**. Tulis **Reason**, lalu **Save shift plan**.
4. **Create draft work order** (SPK) dari rencana yang sudah disimpan. Lanjutkan alur WO biasa: **Ask for release**.

Catatan:
- Pending QC (Before Check) hanya dihitung untuk planning produksi, tidak bisa dikirim.
- Stok yang dipakai bersama dialokasikan sekali ke semua customer sebelum filter.
- Bila stok, QC, demand atau parameter berubah, muncul **Position changed since the plan was saved**: periksa dan simpan ulang dulu.
- Menekan ulang **Create draft work order** membuka WO yang sama. Rencana tambahan setelah WO dibuat menjadi revisi baru; revisi lama tetap tersimpan.
- Tanpa BOM yang berlaku atau konversi batch, **Material capacity** tidak terhitung.
- Bila plant menyimpan bahan milik customer, MPS, MRP dan Daily Recovery ditolak dengan pesan **Customer material in stock**. Untuk sementara, buat work order manual dengan **Production customer** yang sesuai.

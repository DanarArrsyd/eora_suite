# Panduan Pengguna EORA

Panduan singkat per role: menu yang terlihat, untuk apa, langkah kerja harian, dan siapa yang menyetujui. Menu di sidebar hanya menampilkan halaman yang sudah jalan dan yang bisa Anda kerjakan (D-126). Kalau sebuah menu tidak muncul, role Anda tidak memerlukannya — minta administrator tenant menambah role bila memang perlu.

## Alur kerja utama

1. **Demand** — DLV mencatat Sales Order dari PO customer (dengan kanban / jadwal kirim) dan forecast per minggu; PPC membacanya.
2. **Perencanaan** — PPC menghitung **MPS** (apa yang dibuat per minggu); CSA menyetujui. PPC menjalankan **MRP** (apa yang dibuat dan dibeli) lalu merilis planned order menjadi **Work Order** Draft.
3. **Rilis WO** — PPC meminta rilis; orang kedua (CSA) merilis. Saat rilis, BOM dan routing yang berlaku disalin ke WO.
4. **Bahan** — MAT menerima bahan dari supplier, QC melepas bahan yang perlu inspeksi, MAT mengeluarkan bahan ke work center.
5. **Produksi** — PRL membuka **Production Run**, mencatat hasil (good / NG / scrap) dan konsumsi bahan, lalu menutup run.
6. **Kualitas** — QC memutuskan hasil produksi (release / hold / rework / reject).
7. **Gudang FG** — FGW menerima barang jadi yang sudah di-release QC ke rak FG.
8. **Pengiriman** — DLV mengalokasikan FG ke jadwal kirim, FGW mengambil (pick) ke staging, DLV mengirim (shipment).
9. **Kontrol stok** — INV mengurus koreksi stok, reversal, dan tutup buku bulanan.

## Tampilan daftar (semua menu)

- **Kolom**: tombol kolom di kanan toolbar memilih kolom yang tampil; kolom penting (mis. jumlah dan status) selalu tampil.
- **Comfortable / Compact**: kerapatan baris tabel.
- **Views**: simpan pencarian, filter, urutan dan kolom dengan nama (**Save current view…**), lalu buka lagi kapan saja. View hanya terlihat oleh Anda; menyimpan nama yang sama menggantinya. Hapus lewat **Delete a view**.
- **Angka dan tanggal**: kuantitas memakai pemisah ribuan dan satuan (mis. `1,000 PCS`, `44.500 KG`); nol tampil sebagai `–`; tanggal seperti `27 Nov 2026`.
- **Owner**: di Stock Overview, RM Receipts dan Material Lots, filter **Owner** memilih bahan **Company** atau milik satu customer.

## Siapa menyetujui apa (pembuat ≠ penyetuju)

| Pekerjaan | Dibuat oleh | Disetujui / dirilis oleh |
|---|---|---|
| Sales Order dan forecast | DLV | — (DLV confirm / activate sendiri) |
| MPS | PPC | CSA |
| Work Order | PPC | CSA |
| Koreksi stok (adjustment) | INV | CSA |
| Reversal dokumen | pemilik dokumen (MAT, PRL, QC, FGW, DLV, PPC, INV) | INV atau CSA (bukan peminta) |
| Tutup buku bulanan | INV | CSA |
| Master data (item, customer, supplier, …) dan import Excel | SUP | — (langsung berlaku) |
| Routing dan BOM | SUP | CSA (bukan pembuatnya) |
| Conversion data (berat, coil/sheet) | SUP | CSA atau QCS |
| Release QC di luar kebijakan | QCI / QCS | QCS (bukan pemintanya) |
| WO close exception | PPC | CSA / INV |

Aturan ini juga dijaga database: siapa pun — termasuk Supervisor — tidak bisa menyetujui pekerjaannya sendiri.

Setelah Anda mengajukan sesuatu, layar dokumen menampilkan **siapa yang harus menyetujui** (role dan nama orangnya), dan mereka langsung menerima pesan di **lonceng** dengan link ke dokumen itu. Saat diputuskan, Anda menerima pesan **Approved / Returned / Rejected** beserta alasannya. Tidak perlu menebak harus minta ke siapa (D-129).

## Panduan per role

| Role | File |
|---|---|
| Supervisor (akun pemilik) | [SUP.md](SUP.md) |
| Company Super Admin / Approver | [CSA.md](CSA.md) |
| PPIC Planner | [PPC.md](PPC.md) |
| Materials PIC | [MAT.md](MAT.md) |
| Production Leader | [PRL.md](PRL.md) |
| Production Operator | [OPR.md](OPR.md) |
| Quality Inspector | [QCI.md](QCI.md) |
| Quality Supervisor | [QCS.md](QCS.md) |
| FG Warehouse | [FGW.md](FGW.md) |
| Delivery | [DLV.md](DLV.md) |
| Inventory Controller | [INV.md](INV.md) |
| Auditor / Viewer | [AUD.md](AUD.md) |
| Tenant Administrator | [TENANT_ADMIN.md](TENANT_ADMIN.md) |

Role **Approver (APR)** dan **Module Admin (MAD)** belum punya izin (D-068); akun dengan role itu saja belum melihat menu apa pun.

## Istilah singkat

- **Material at Production** — bahan yang sudah dikeluarkan ke work center dan belum dipakai / dikembalikan (bukan "WIP").
- **Pending QC** — hasil produksi yang menunggu keputusan QC; belum dihitung sebagai stok FG.
- **QC Released** — sudah lolos QC, masih di area QC; baru jadi stok FG setelah FG Receipt.
- **Firm** — jadwal kirim yang sudah pasti dari Sales Order; **net forecast** — forecast yang belum tertutup order firm.
- **Reversal** — cara membetulkan dokumen yang sudah diposting (tidak pernah dihapus).

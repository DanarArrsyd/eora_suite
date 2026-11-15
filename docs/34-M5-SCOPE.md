# 34 — M5 Scope: Bisa dipakai (MPS/MRP dasar, rekonsiliasi dasar, Control Tower data asli) — lembar persetujuan

**Status:** **DISETUJUI 2026-11-15 (D-120)** — owner memilih semua usulan: satu M5 dua fase (M5-D1), forecast + revisi PO/SO (M5-D2), horizon MPS 12 minggu (M5-D3), M5-D4..D10 sesuai usulan. Implementasi di branch `feat/m5-…`, PR berurutan mengikuti §1; aturan bisnis yang belum diputuskan ditanyakan per langkah.
**Basis:** doc 11 (M5 = B5 MPS/MRP dasar, B8 rekonsiliasi dasar, B9 sebagian, data demo), doc 21 (definition of done R1 vertical slice), doc 23 (MPS, gate G-MPS-1..8 disetujui D-046; skenario #51–#68), doc 02 + ADR-0005 (rekonsiliasi, indikator), doc 14 #2–#5, #21, #23, #24, doc 15 (BR-DEM, BR-PLN-03..07, BR-OUT-07, BR-REC), doc 19 (Control Tower), D-038 (revisi PO), D-039 (blanket), D-040 (forecast), D-041/D-044 (bucket mingguan ISO, tanggal harian), D-043 (kelas supply), D-059 (Option M default), D-061 + D-075 (toleransi strict + measurement floor), D-063 (WO close: pindah ke WO lain), D-087 (inbound schedule supplier), dan yang ditunda M4 ke M5 (doc 33 §2, D-111, D-117).
**Tujuan M5:** Anda bisa menjalankan **R1 vertical slice lengkap** di data demo (doc 21): customer PO → SO + jadwal kirim → **MPS mingguan → planned order → WO** → bahan dikeluarkan → konsumsi terukur, sisa, return → good / NG / scrap → QC sebagian → FG → kirim sebagian → trace balik → **case rekonsiliasi untuk skenario sheet 25 pcs** yang diselesaikan dua orang. Control Tower menampilkan data asli untuk layar yang dipilih.

## 1. Dalam scope
Dua fase berurutan dengan satu persetujuan scope ini; setelah fase A ada titik coba (UAT singkat) sebelum fase B. Setiap langkah: migration + rollback, tes RLS sebagai role non-superuser, OpenAPI, docs, layar + e2e mock, PR sendiri.

**Fase A — Perencanaan (B5)**
1. **Parameter perencanaan:** per item + plant: lead time produksi dan lead time beli (hari kerja kalender plant), safety stock (kosong = `NOT CONFIGURED`, G-MPS-2), lot-for-lot. **Inbound schedule supplier** (supplier, item, qty, tanggal perkiraan, status; D-087) sebagai projected supply dan referensi RM receipt. Layar: Master Data → **Planning Parameters**, Materials → **Inbound Schedule**.
2. **Demand lanjutan (B4):** **forecast** berversi per customer + part + plant, dikonsumsi firm demand dalam bucket yang sama, tidak pernah dijumlah dua kali (D-040, BR-DEM-08); **revisi customer PO/SO** dengan riwayat revisi, hanya outstanding yang dipindah, delivered tidak pernah ditulis ulang (D-038). Layar: Demand → **Forecasts**, revisi di **Sales Orders**.
3. **MPS (G-MPS-1..4, 7, 8):** versi MPS per plant, bucket mingguan ISO dengan tanggal harian tetap terlihat (D-041, D-044); projected available balance time-phased, stok FG dipakai sekali (#51, **#65 regresi F-1 680 pcs wajib**); Firm Available (A) dan Projected (B) dinetting, **Pending QC (C) tampil tapi tidak dinetting** (D-043); lot-for-lot dengan lead time, tanggal kirim harian paling awal yang belum tertutup dilindungi (G-MPS-4); peringatan kapasitas (mesin + die + kalender shift + cycle time standar, G-MPS-7); per plant (G-MPS-8). PPC menyiapkan, **CSA menyetujui** (bukan penyiapnya, SoD-07). doc 23 #51–#68. Layar: PPIC → **Master Production Schedule**.
4. **MRP dasar (G-MPS-5):** ledakan BOM multi-level dari MPS yang approved: item Semi-Finished menjadi planned WO anak (Option M, D-059); kebutuhan bahan dalam UOM bahan, scrap allowance dihitung sekali (#68); netting terhadap stok RM `UNRESTRICTED` + inbound schedule; hasil: **planned WO** (FG dan Semi-Finished) dan **daftar kekurangan bahan** (tanpa dokumen pembelian; purchasing/PO tidak ada di slice doc 11). Layar: PPIC → **MRP Results** (planned orders, shortages).
5. **Planned order → WO:** PPC merilis planned WO menjadi WO Draft dengan pegging ke requirement/schedule line (BR-DEM-12), lalu release biasa oleh orang kedua (D-109). WO manual tetap ada untuk di luar MPS dengan alasan (G-MPS-6).
6. **WO berantai Semi-Finished (Option M):** WO FG memakai lot Semi-Finished hasil WO tahap sebelumnya (issue dari area/rak Semi-Finished ke Material at Production); genealogy lintas tahap; tanpa kata WIP.

**Fase B — Rekonsiliasi dan Control Tower (B8, B9 sebagian)**
7. **Konsumsi by-difference:** bila tidak terukur, konsumsi dicatat `PROVISIONAL` dan membuka case `UNMEASURED_CONSUMPTION` (ADR-0003, doc 14 #2–#5).
8. **Output teoritis dan indikator:** T dari bahan terpakai × conversion profile; **over-report guard BR-OUT-07** (dipindah dari M4, D-111); indikator **GYV / PvS / UMV** per posting dan per WO (doc 02, ADR-0005), tanpa label pencurian (CLAUDE.md).
9. **Rekonsiliasi per WO + case:** engine doc 02 saat WO close (dan bisa dijalankan manual); variance final tak terjelaskan di atas measurement floor membuka case (D-061, D-075); case dijelaskan dengan bukti dan **diselesaikan dua orang** (maker-checker); indikator BR-PLN-03 di kartu Closing WO; **pindah sisa bahan ke WO lain** (D-063). Skenario wajib: **sheet 25 pcs** (doc 21). Layar: Traceability → **Reconciliation Cases**, kartu di WO.
10. **Control Tower data asli (B9 sebagian):** read model + freshness (ADR-0016) untuk layar yang dipilih (M5-D8); layar lain tetap mock dengan label "Mock data".
11. **Penutup:** `eora:demo:m5` (R1 vertical slice lengkap lewat endpoint asli, termasuk case sheet 25 pcs), stack e2e M5, skrip UAT `docs/uat/M5-UAT.md`, checklist exit.

**Progres:** Langkah 1 (parameter perencanaan per item + plant: lead time produksi/beli dalam hari kerja, safety stock qty UOM dasar atau NOT CONFIGURED, lot-for-lot; PPC ubah langsung + audit; inbound schedule supplier oleh MAT/PPC, receipt boleh menyebut barisnya, status dari receipt posted, tidak pernah menolak receipt — D-121; migration 003600; layar Master Data → **Planning Parameters**, Materials → **Inbound Schedule**, field "Against inbound line" di RM Receipt) — PR `feat/m5-step1-planning-params` (merged #85). Langkah 2 (forecast versi per customer + plant, qty per customer part per minggu ISO, PPC draf + aktifkan, firm demand minggu yang sama mengonsumsi forecast saat dibaca; revisi schedule line Firm oleh PPC dengan alasan: line lama SUPERSEDED dengan delivered-nya, line baru revision+1, split dipetakan PPC, outstanding tercatat di schedule_reallocations; reversal shipment line yang sudah direvisi tetap bisa — D-122; migration 003700; layar Demand → **Forecast**, tombol **Revise** di Sales Order) — PR `feat/m5-step2-forecast-revision`.

## 2. Di luar scope M5
- Blanket order / Unscheduled Blanket Balance (D-039) dan import jadwal kirim → M7 (usulan M5-D2).
- Rework execution dan re-inspeksi NG → M7; custody dua langkah, stock count (R2), transfer antar plant, purchasing/PO, APS dan penjadwalan otomatis (R2), offline PWA posting, push notification, staging online (M6).

## 3. Keputusan (usulan)
| ID | Topik | Usulan | Alasan |
|---|---|---|---|
| M5-D1 | Bentuk milestone | Satu M5, **dua fase** (A perencanaan, B rekonsiliasi + CT), titik coba setelah fase A, satu exit | Tetap sesuai urutan D-054; owner bisa mencoba MPS/MRP sebelum rekonsiliasi dibangun. |
| M5-D2 | Demand lanjutan | **Forecast (D-040) dan revisi PO/SO (D-038) masuk M5**; blanket (D-039) dan import jadwal → M7 | MPS butuh forecast; revisi dibutuhkan agar delivered tidak ditulis ulang. Blanket dan import tidak dibutuhkan DoD R1. |
| M5-D3 | Horizon MPS | **12 minggu** default per plant, bisa diubah | Cukup untuk lead time stamping; bukan keputusan bisnis final — owner pilih. |
| M5-D4 | Siapa menyiapkan dan menyetujui | PPC menyiapkan MPS dan merilis planned order; **CSA menyetujui MPS** (SoD-07); release WO tetap D-109 | Sama dengan pola orang kedua D-060. |
| M5-D5 | Hasil MRP untuk bahan | **Daftar kekurangan bahan** + inbound schedule sebagai referensi; tanpa dokumen pembelian | Tidak ada slice purchasing/PO di doc 11 (B1–B10); dokumen pembelian butuh keputusan scope sendiri. |
| M5-D6 | Lead time | Hari kerja kalender plant, per item + plant; tanpa routing-based lead time di M5 | Sederhana, cukup untuk lot-for-lot (G-MPS-4). |
| M5-D7 | Kapan rekonsiliasi jalan | **Saat WO close** (wajib) + manual oleh PPC/INV | Variance final baru ada setelah WO selesai (doc 02). |
| M5-D8 | Layar Control Tower data asli | **Weekly demand (CT-9), FG availability A/B/C, material shortage, status WO, exception** | Layar yang dipakai untuk DoD R1; sisanya tetap mock. |
| M5-D9 | Peran case rekonsiliasi | PRL/PPC memberi penjelasan + bukti; **INV atau CSA menyetujui penyelesaian** (bukan yang menjelaskan) | doc 18 baris `recon.case`, D-060. |
| M5-D10 | Rework execution | → M7 | Tidak dibutuhkan DoD R1. |

## 4. Acceptance (exit M5)
1. doc 23 #51–#68 hijau, termasuk **#65 regresi F-1 680 pcs** sebagai unit test.
2. doc 14 #2–#5, #21, #23, #24 hijau; skenario sheet 25 pcs membuka case dan diselesaikan dua orang.
3. Forecast dan firm demand tidak pernah dijumlah dua kali (D-034, D-040); revisi tidak memindahkan delivered (D-038); requirement record turunan, bukan sumber demand (BR-DEM-12).
4. Pending QC tidak pernah dinetting (D-043); safety stock kosong tampil `NOT CONFIGURED`.
5. SoD: MPS disiapkan ≠ disetujui; case dijelaskan ≠ diselesaikan; juga lewat SQL.
6. Konkurensi + idempotency semua endpoint posting/aksi baru; isolasi tenant + scope plant; OpenAPI tanpa drift; migrate round-trip; saldo = ledger setelah skenario penuh.
7. Gate frontend doc 22 §4 + stack e2e M5 (PO → SO → MPS → planned WO → WO → … → shipment → trace → case).
8. Dokumen ter-update (docs 02, 04, 13, 15, 17, 18, 19, 23, 24) dan owner menjalankan `docs/uat/M5-UAT.md`.

## 5. Cara kerja
- Branch per langkah (`feat/m5-…`), PR berurutan mengikuti §1; CI hijau + review Codex bersih sebelum merge; tanpa auto-merge.
- Setiap langkah: tanya dulu bila ada aturan bisnis yang belum diputuskan; tidak mengisi keputusan bisnis sendiri.
- Berhenti dan lapor bila ada temuan keamanan atau keputusan baru.

**Pilihan owner:** ☑ Setuju scope M5 · ☑ Keputusan M5-D1..D10 sesuai usulan (2026-11-15)

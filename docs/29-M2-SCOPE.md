# 29 — M2 Scope: Core master data (B2) — lembar persetujuan

**Status:** **DISETUJUI 2026-10-14 (D-072)** — owner: "setuju scope M2 sesuai usulan" (termasuk M2-D1..D6 sesuai usulan). Implementasi di branch `feat/m2-…`, PR berurutan mengikuti §1.
**Basis:** doc 26 v14 (B2, keputusan §11 disetujui D-058), D-059 (O-003: opsi M default, S per item), D-060 (maker ≠ checker, tanpa threshold), D-044 (kalender), D-046 (G-MPS-5/7), doc 17 §2, doc 18 §2, doc 08, ADR-0001/0007/0008/0013, `docs/uiux/`. M1 selesai (D-071).
**Tujuan M2:** Anda bisa **mengisi dan memelihara master data manufaktur** — plant, gudang/lokasi, work center, mesin, dies, item, UOM, customer, customer part, supplier, routing multi-operasi dan BOM — lewat layar, dan master bervolume besar juga lewat **import Excel** (daftar template di §1 langkah 6), dengan versi + approval dua orang untuk routing/BOM/map dan audit untuk semuanya. Belum ada stok, transaksi atau planning.

## 1. Dalam scope
**Backend (`apps/api`), PR berurutan (setiap langkah: migration + rollback, tes T-ISO/RLS sebagai role non-superuser, OpenAPI, docs)**
1. **Organisasi fisik:** pemeliharaan company/plant (menggantikan `eora:demo:plant` untuk pemakaian normal), `warehouses`, `locations` (lokasi virtual per plant, `MAT_AT_PROD` per work center, ADR-0002 §3), `work_centers` (+ kapasitas dasar D-046), `machines`, `dies` + `die_items` (dies per plant, doc 26 §2.1).
2. **Part dan mitra:** `uoms`, `uom_conversions` (per item, berversi), `material_specs`, `items` (RAW / SEMI_FINISHED / FINISHED / SCRAP / CONSUMABLE; kode immutable setelah dipakai; nonaktif, tidak dihapus), `customers`, `customer_items` (tanpa rentang efektif yang tumpang tindih), `suppliers`. Penamaan Semi-Finished `<PART>-<STAGE>` (D-059); tidak ada label WIP.
3. **Routing:** `process_types` (didefinisikan tenant), `routings` per item + plant (D-B2-05), `routing_versions` (SY per versi, D-B2-02; rentang efektif inklusif + exclusion constraint `btree_gist`, D-B2-08), `routing_operations` (+ mesin, tooling, inspeksi), routing alternatif (D-B2-04). Approval dan supersession lewat `lifecycle_api.approve_routing_version` (SECURITY DEFINER, `eora_lifecycle_definer`, signed context, maker ≠ checker, tanpa efektivitas mundur — doc 26 §2.4a, D-B2-14). Allowlist definer di CI diperbarui.
4. **BOM:** `bom_versions` / `bom_lines` (`gross_qty_per` dalam UOM material, scrap/yield dipakai sekali), `bom_routing_maps` + baris (satu map APPROVED per pasangan versi, D-B2-01 a), immutable setelah approve, konsistensi item via composite FK (doc 26 §2.5).
5. **Engineering data konversi:** `item_weights` (NW, berversi) dan `conversion_profiles` (SHEET / COIL, input tervalidasi per `formula_code` dari doc 02, status DRAFT → VALIDATED → RETIRED). **Hanya menyimpan dan memvalidasi data**; perhitungan GYV/PvS/UMV tetap B8 (M5).
6. **Import Excel (exit B2, doc 11):** template berversi untuk gudang + lokasi, UOM + konversi UOM per item, material spec, item, customer, customer part, supplier, process type, work center, mesin, dies + die-item, routing, BOM, map BOM–routing. **Hanya lewat layar** (jumlahnya kecil atau butuh validasi engineering per record): company/plant, `item_weights` (NW) dan `conversion_profiles`.
   - Pipeline doc 26 §5: staging, preview + file error, reservasi atomik, lease + fencing, commit per chunk 500, retry tanpa commit ganda.
   - All-or-nothing untuk routing/BOM/map; hanya baris valid untuk template lain (D-B2-07; doc 26 menyebut item/customer/customer part — usulan: aturan yang sama untuk template master baru di atas).
   - **Routing, BOM dan map** hasil import masuk sebagai versi/revisi **DRAFT** dan tetap harus di-approve orang kedua (tidak ada bypass approval). Master lain **tidak punya siklus approval**: baris valid langsung tersimpan dengan status normalnya (mis. item `ACTIVE`), diaudit per commit.
7. **Pencarian dan daftar:** QueryDefinition (ADR-0008) untuk semua master di atas, filter/sort/pagination server-side, `q` prefix + trigram (`pg_trgm`, D-B2-09), export lewat query yang sama, cek EXPLAIN untuk daftar besar.
8. **Permission master data** (lihat keputusan M2-D1) + template role, audit `audit_events` untuk create/update/submit/approve/supersede/retire/import.

**Frontend (`apps/web`), design system yang disetujui**
- Menu **Master Data**: Plants & Warehouses, Work Centers & Machines, Dies, Items, Customers & Customer Parts, Suppliers, Process Types, Routings, BOMs, Conversion Data, Import.
- Daftar = `EoraDataTable` + `EoraFilterBar` (state di URL, server-side); form = `EoraForm`; tanpa select/date native (preferensi owner); status badge; layar routing dengan urutan operasi, riwayat versi dan aksi Submit / Approve / Revise; import wizard (unggah → mapping → preview error → commit → hasil).
- Data asli lewat typed client; modul lain tetap berlabel **Mock data**. Layar baru dirancang dengan skill Frontend Design mengikuti `docs/uiux` (prototype v2.1 belum punya layar master data).

**Untuk testing Anda**
- Seeder demo diperluas: "Demo Stamping" dengan item sintetis, 4 customer, routing contoh doc 26 B2-AT-02 (Part A/B/C), BOM + map, beberapa dies dan mesin.
- File Excel contoh per template (data sintetis), termasuk satu file dengan error yang disengaja.
- Skrip UAT M2 langkah demi langkah (`docs/uat/M2-UAT.md`).

**Progres:** langkah 1a (fondasi: permission `master.*`, `eora:tenants:sync`, QueryDefinition `GET /api/v1/query/{resource}`, company/plant/gudang/lokasi + lokasi virtual) — PR `feat/m2-step1-org`. Langkah 1a selesai (PR #25), 1b selesai (PR #26; `die_items` pindah ke langkah 2 bersama item). Langkah 1c (layar organisasi di item navigasi yang sudah disetujui, Administration → Organization: Company; Plants & Warehouses = seksi Plants/Warehouses/Locations; Work Centers = seksi Work centers/Machines/Dies & tooling. Memindah Machines/Dies dari Maintenance → Assets = usulan, belum disetujui — **diputuskan D-076, 2026-10-15:** semua master organisasi/resource/engineering di modul Master Data, Maintenance (R2) hanya alias; dipindah di langkah 3b) — PR `feat/m2-step1c-org-screens`. Langkah 1c selesai (PR #27). Langkah 2a selesai (PR #28; D-073). Langkah 2b selesai (PR #29). Langkah 2c (layar di modul baru Master Data, D-074: Items + material spec, Units & Conversions, Customers, Customer Part Mapping, Suppliers; Die parts sebagai seksi di Work Centers) — PR `feat/m2-step2c-part-screens`. Langkah 2c selesai (PR #30). Langkah 3a (backend routing: process type, routing + versi, operasi, submit/reject/approve lewat `lifecycle_api`, revise + supersede; migration `001300`) — PR `feat/m2-step3a-routing`. Langkah 3a selesai (PR #31). Langkah 3b (layar: menu Master Data D-076 — Organization/Parts/Partners/Engineering, URL Administration lama diarahkan; Process Types; Routings + halaman routing: riwayat versi, operasi, approval) — PR `feat/m2-step3b-routing-screens`; skrip UAT dimulai di `docs/uat/M2-UAT.md`. Langkah 3b selesai (PR #33). Langkah 4a (backend BOM + map BOM–routing, D-078/D-079; migration `001400`) — PR `feat/m2-step4a-bom`. Berikutnya 4b (layar BOM dan map di Master Data → Engineering).

## 2. Di luar scope M2
- Stok, ledger dan posting (M3); Sales Order, WO, MPS/MRP (M4/M5); snapshot routing di WO (B5).
- Perhitungan varians dan rekonsiliasi (B8); O-033 diputuskan D-075 (2026-10-15: batas ukur timbangan berlaku); tetap di luar M2, dipakai di B8.
- Proses subcontract (D-B2-11), modul transfer tooling, APS/penjadwalan.
- Approval policy yang bisa dikonfigurasi dan threshold (D-060: semua approval versi = orang kedua yang independen).
- Provider storage dan antivirus produksi (M6, bersama hosting O-008) — lihat M2-D3.

## 3. Keputusan yang dibutuhkan sebelum mulai (usulan, belum diputuskan)
| ID | Keputusan | Usulan | Kenapa |
|---|---|---|---|
| M2-D1 | Permission master data | Resource baru `master.org` (company/plant/gudang/lokasi/work center/mesin/dies), `master.part` (item/UOM/spec/customer/customer part/supplier), `master.routing` (process type/routing/BOM/map), `master.conversion` (NW, conversion profile). Aksi V/C/S/A + X untuk export, import lewat `exchange.import` yang sudah ada. Template awal: CSA = VCSA semua; PPC = VCS `master.routing` + `master.part`, V lainnya; MAT/INV = V + C `master.part`; QCS = V + A `master.conversion`; PRL/QCI/FGW/DLV/AUD = V; APR = A (diisi tenant, D-068); `master.org` C/S hanya CSA. `master.*.approve` sensitif (D-B1-25). | Doc 18 §1 belum punya baris master data; doc 26 menyebut `master.routing.approve` sebagai usulan. |
| M2-D2 | Library Excel | **OpenSpout** (streaming, MIT) untuk baca/tulis XLSX, versi dipin setelah cek kompatibilitas Laravel 13/PHP 8.4, ADR baru. Alternatif: PhpSpreadsheet (lebih lengkap, boros memori). | Doc 08: library dipilih via ADR; ribuan baris harus muat memori worker. |
| M2-D3 | Penyimpanan file + scan | Compose menambah **MinIO** (S3-compatible, versi dipin, ADR-0007); upload dicek tipe (XLSX saja), ukuran (≤ 10 MB), zip-bomb, makro/eksternal link ditolak. Antivirus (ClamAV atau layanan hosting) diputuskan di M6 bersama O-008; titik scan sudah disiapkan. | ADR-0007 masih PROPOSED untuk S3; antivirus di laptop lokal berat dan bergantung pilihan hosting. |
| M2-D4 | Kode item | Kode bebas dari tenant (`^[A-Z0-9][A-Z0-9._/-]{0,39}$`, huruf besar), tanpa auto-numbering di M2 (D-049 untuk dokumen transaksi, bukan master). | Pelanggan stamping biasanya memakai nomor part sendiri. |
| M2-D5 | Demo tengah milestone | Satu demo di akhir M2; **opsional** demo antara setelah langkah 1–2 (organisasi + part) bila Anda ingin menilai layar daftar/form lebih awal. | M2 besar; masukan UI lebih awal mengurangi rework di routing/BOM. |
| M2-D6 | Bacaan B2-AT-70 | Diuji seperti di §4 butir 1 (baris ter-commit yang berubah sebelum retry dilewati); doc 26 §10 dikoreksi kata-katanya di PR langkah 6 | Kalimat doc 26 mengandaikan approval item yang tidak ada di model item |

## 4. Acceptance (exit M2)
1. Skenario doc 26 §10 yang menjadi milik B2 hijau sebagai tes otomatis: **B2-AT-01..14, 18, 20..23, 25..44, 46..55, 57..62, 69..73** — termasuk impor 1.278 item + set sintetis lebih besar tanpa batas maksimum, approval/isolasi definer (AT-25..36), tooling per plant (AT-37..41), integritas map (AT-42..44, 46, 53..55, 57) dan fencing import (AT-47..52, 58..62, 69..73).
   - **Tidak** di M2 (hook milik slice lain, tabelnya belum ada): AT-15, 24, 45, 56, 74 (B5 — snapshot WO); AT-16, 17, 63..68, 75, 76 (B6 — posting dan `consumption_line_allocations`); AT-19 (B8). Tes ini ditulis saat slice tersebut.
   - AT-70 berbicara tentang draft item yang di-approve, padahal item tidak punya approval (doc 26 §2.2); di M2 diuji sebagai: baris yang sudah di-commit lalu diubah lewat layar sebelum retry tetap dilewati retry (tanpa update, tanpa gagal). Lihat M2-D6.
2. Isolasi: T-ISO diperluas ke semua tabel B2 (composite FK, forced RLS), tes sebagai role non-superuser; allowlist definer persis di CI.
3. Approval dua orang: preparer tidak bisa approve versinya sendiri, juga lewat SQL langsung; versi APPROVED/SUPERSEDED tidak bisa diubah (trigger), approval paralel tidak menghasilkan versi tumpang tindih (B2-AT-21).
4. Import: upload ganda file yang sama → satu job; retry tidak meng-commit baris dua kali; worker hilang → job tidak terkunci selamanya; hasil import DRAFT.
5. Daftar master dengan ribuan baris: filter/sort/pencarian server-side, export memakai query yang sama; EXPLAIN memakai index.
6. Migrate/rollback round-trip; OpenAPI semua endpoint B2 + error; typed client tanpa drift.
7. Quality gate frontend doc 22 §4 hijau (lint, typecheck, unit, e2e mock, axe) + stack e2e diperluas: buat item → routing → submit → approve oleh user kedua → import file contoh.
8. Dokumen ter-update di PR yang sama (docs 13, 17, 18, 24, 26, ADR baru untuk M2-D2/D3).
9. **Demo ke owner:** Anda menjalankan `docs/uat/M2-UAT.md` di lokal dan menyatakan OK.

## 5. Cara kerja
- Branch per langkah (`feat/m2-…`), PR berurutan mengikuti §1; CI hijau di setiap PR; tanpa auto-merge; Anda yang merge.
- Berhenti hanya bila ada temuan keamanan atau keputusan baru yang benar-benar dibutuhkan; keputusan bisnis tidak diisi sendiri.
- Ukuran: lebih besar dari M1 di sisi layar (11 area master data) dan import; tidak ada tanggal yang dijanjikan.

**Pilihan owner:** ☑ Setuju scope M2 · ☑ Keputusan M2-D1..D6 sesuai usulan (2026-10-14)

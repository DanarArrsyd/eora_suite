# 32 — M3 Scope: Ledger (B3) — lembar persetujuan

**Status:** **DISETUJUI 2026-10-29 (D-096)** — owner: "Setuju semua" (scope §1–§5 dan keputusan M3-D1..D10 sesuai usulan). Implementasi di branch `feat/m3-…`, PR berurutan mengikuti §1.
**Basis:** ADR-0002 (semantik ledger), ADR-0014 (posting engine), doc 04 §Inventory ledger posting specification (matrix 13 baris) dan §Movement codes (D-084), doc 17 §H Inventory, doc 15 BR-INV-01..11, BR-PER-01..04, BR-ISS-06, D-043 (Firm Available), D-044 (kalender, production date), D-048 (`MAT_TRANSFER`), D-049 (penomoran), D-060 (setiap reversal/adjustment butuh approver kedua), D-062 (incoming release minimal), D-086 (cek ketersediaan M4). M2 selesai (D-095).
**Tujuan M3:** Anda bisa **menerima bahan baku dan memindahkan stok, lalu melihat saldo yang selalu benar** per plant, lokasi, item, lot dan status — dengan riwayat movement yang tidak bisa diubah, koreksi lewat reversal yang di-approve orang kedua, dan periode yang bisa ditutup. Belum ada WO, issue ke produksi, produksi, QC atau pengiriman (M4).

## 1. Dalam scope
**Backend (`apps/api`), PR berurutan (setiap langkah: migration + rollback, tes RLS sebagai role non-superuser, OpenAPI, docs)**
1. **Fondasi ledger:** `lots`, `inventory_documents`, `inventory_movements` (append-only, `movement_code` CHECK katalog D-084), `stock_balances` (`CHECK qty >= 0`, hanya lokasi fisik), `stock_allocations` (tabel + aturan, dipakai M4), `genealogy_edges`, `inventory_periods`, counter nomor dokumen (D-049), `idempotency_records`. Grant: runtime role tanpa UPDATE/DELETE pada movement; hanya service yang menulis (BR-INV-01).
2. **`InventoryPostingService`:** satu transaksi — validasi → kalender (production date, shift, BR-PER-04) → periode OPEN (BR-PER-01) → lock saldo urut kunci (`FOR UPDATE`, `lock_timeout` 3 s) → cek saldo ≥ 0 dan alokasi → dokumen + leg + genealogy → proyeksi saldo → outbox + audit → commit. Idempotency key wajib (replay = hasil sama; payload beda → 409). Retry terbatas pada `40001`/`40P01`. Kode movement dipilih service dari matrix, bukan dari klien.
3. **Penerimaan bahan (`RM_RECEIPT`, baris 1):** dari `V_SUPPLIER` ke gudang RM; lot dibuat saat terima **hanya untuk item lot-controlled** (item tanpa kontrol lot memakai saldo tanpa lot, M3-D4); item "incoming release required" masuk `BLOCKED`, lainnya `UNRESTRICTED`. **Incoming release** (`INCOMING_RELEASE`, D-062): `BLOCKED → UNRESTRICTED` manual.
4. **Transfer (`MAT_TRANSFER`, baris 4):** antar lokasi/gudang dalam satu plant (satu langkah). Transfer antar plant dua langkah — lihat M3-D6.
5. **Split lot / remnant (`LOT_SPLIT`, baris 7):** lot anak di lokasi yang sama + genealogy edge.
6. **Adjustment (`STOCK_ADJUSTMENT`, baris 12):** diminta dengan reason code, di-approve orang kedua (D-060, SoD-02); tidak pernah dari variance yang tidak terjelaskan (D-024).
7. **Reversal (`REVERSAL`, baris 13):** diminta dengan alasan, di-approve orang kedua (D-060); leg dibalik dengan kode yang sama + `reverses_movement_id`; ditolak bila saldo jadi negatif (`REVERSAL_BLOCKED_BY_DOWNSTREAM`).
8. **Periode:** daftar periode per plant, tutup periode; posting ke periode tertutup ditolak (BR-PER-01). Koreksi periode tertutup dan reopen (BR-PER-02/03) — lihat M3-D8.
9. **Daftar dan pencarian (QueryDefinition, ADR-0008):** saldo stok (filter plant/gudang/lokasi/item/lot/status, Available = UNRESTRICTED − alokasi aktif, BR-INV-08), movement ledger, dokumen, lot; export lewat query yang sama (D-092). Rebuild proyeksi dari ledger + cek "proyeksi = Σ ledger" (BR-INV-11).

**Frontend (`apps/web`), design system yang disetujui**
- Materials → Receiving: **RM Receipts** (daftar + form terima), **Incoming Release**; Materials → Lots: **Material Lots** (+ split). Inventory → Stock: **Stock Overview**, **Raw Material**; Inventory → Ledger: **Movement Ledger**; Inventory → Control: **Adjustments**, **Reversals**, **Period Close**. Layar lain di modul itu (Material Issue, Semi-Finished, Material at Production, Allocations, Stock Count, …) tetap berlabel Mock data sampai milestone-nya.
- Form posting dengan konfirmasi (dokumen yang sudah posted tidak bisa diedit, hanya di-reverse); tidak ada select/date native (preferensi owner); halaman dokumen menampilkan leg, saldo sebelum/sesudah dan link reversal.

**Untuk testing Anda**
- Seeder demo: penerimaan coil/sheet contoh ke tenant demo lewat service posting asli (sama seperti `eora:demo:master` di M2).
- Skrip UAT M3 (`docs/uat/M3-UAT.md`) dan stack e2e: terima → transfer → split → adjustment (dua orang) → reversal (dua orang) → saldo dan ledger cocok.

## 2. Di luar scope M3
- WO, alokasi ke WO, issue ke produksi, custody, return, konsumsi, output produksi, QC disposition, FG receipt, pick/shipment (M4; matrix baris 2, 3, 5, 6, 8–11 diposting oleh dokumennya di M4, lihat M3-D2).
- Stock count / stock opname (R2 di navigasi; lihat M3-D7), consignment/milik customer (O-005, M7), serial number (V2), partisi bulanan ledger (doc 20, saat volume membutuhkan).
- Cek ketersediaan untuk planning (D-086, M4) dan netting MPS (M5).

## 3. Keputusan (disetujui sesuai usulan, D-096)
| ID | Keputusan | Usulan | Kenapa |
|---|---|---|---|
| M3-D1 | Permission yang belum ada di katalog (doc 18 belum punya penerimaan, transfer, saldo, incoming release) | Resource baru: `materials.receipt` (MAT = VCSP, INV/CSA/PPC/AUD = V), `materials.incoming_release` (QCI/QCS = VP, MAT/INV/AUD = V), `materials.transfer` (MAT/FGW = VCSP, INV = VCSP, AUD = V), `inventory.stock` (semua template = V, + X export). Yang sudah ada tetap: `inventory.adjustment`, `inventory.reversal`, `inventory.period_close`, `inventory.period_reopen`, `production.lot_split`. | Tanpa resource, layar penerimaan/transfer/saldo tidak bisa diberi izin; isi template adalah keputusan bisnis (D-060a: doc 18 jadi default). |
| M3-D2 | Baris matrix yang selesai di M3 | Engine generik untuk semua jenis leg; **diposting dan dites ujung-ke-ujung di M3: baris 1, 4, 7, 12, 13 + incoming release**. Baris 2 (alokasi) di M3 hanya tabel + aturan; baris 3, 5, 6, 8–11 dites saat dokumennya dibuat di M4. | Exit B3 di doc 11 menyebut baris 1–13, tetapi baris itu butuh WO, run, QC dan SO yang belum ada; tes tanpa dokumen asal hanya menguji data buatan. |
| M3-D3 | Nomor lot saat terima | Nomor lot internal otomatis per tenant + item (mis. `L2611-0001`), **plus** kolom nomor lot/heat supplier dan properti mill cert opsional di lot. | Nomor supplier bisa bentrok antar supplier; trace butuh keduanya. |
| M3-D4 | Item yang tidak lot-controlled (`lot_controlled = NO`, mis. oli) | Saldo tanpa lot (`lot_id` NULL di kunci saldo); split lot tidak berlaku untuk item itu. | Item consumable tidak dilacak per lot (sudah ada di master item). |
| M3-D5 | Satuan saat posting | Posting selalu dalam **satuan dasar item**; form boleh menerima satuan lain yang punya konversi berlaku pada tanggal itu, dan dokumen menyimpan qty asli + versi konversi (BR-INV-10). | Saldo satu satuan per item; konversi berversi (D-073) sudah ada. |
| M3-D6 | Transfer antar plant (dua langkah, D-048) | **Ditunda ke M4/M7**: M3 hanya transfer dalam satu plant. | Demo dan pilot awal satu plant (D-071); transfer plant butuh lokasi transit dan approval policy. |
| M3-D7 | Stock count | Tetap R2 (di luar M3); adjustment M3 memakai "koreksi yang di-approve" dengan reason code. | Navigasi menandai Stock Count R2; adjustment tetap butuh dua orang (D-060). |
| M3-D8 | Periode | Periode **bulanan per plant**; periode bulan berjalan dan sebelumnya terbuka otomatis; tutup periode oleh INV (`period_close.post`) + approve CSA. Koreksi periode tertutup (BR-PER-02) dan reopen (BR-PER-03) masuk M3 karena reversal membutuhkannya. Navigasi Period Close R2 → R1. | BR-PER-01 wajib untuk setiap posting; tanpa periode, backdate tidak terkendali. |
| M3-D9 | Ownership | Hanya `OWN` di M3 (kolom tetap ada di kunci saldo). | Consignment = O-005 (M7). |
| M3-D10 | Incoming Release di navigasi | Ubah R2 → **R1** (D-062 sudah memutuskan release manual minimal). | Navigasi masih menandai R2 / keputusan O-013 sebelum D-062. |

## 4. Acceptance (exit M3)
1. Tes posting-matrix untuk baris yang diposting di M3 (M3-D2; exit B3 di doc 11 diselaraskan, baris 3, 5, 6, 8–11 menjadi exit M4): setiap leg memakai kode yang benar, konservasi per dokumen (BR-INV-03), saldo tidak pernah negatif (BR-INV-04), status hanya berubah lewat movement (BR-INV-07).
2. Konkurensi: dua posting paralel ke saldo yang sama tidak pernah membuat saldo negatif atau ganda; deadlock/serialisasi di-retry terbatas (doc 20 §8); replay idempotency mengembalikan hasil yang sama; payload beda → 409.
3. Append-only: UPDATE/DELETE movement ditolak oleh grant (sebagai role runtime, bukan superuser); koreksi hanya lewat reversal; reversal dari reversal ditolak.
4. Dua orang: adjustment dan reversal tidak bisa di-approve oleh peminta (juga lewat SQL langsung).
5. Periode: posting ke periode tertutup → 409 `PERIOD_CLOSED`; production date mengikuti cut-off kalender plant (BR-PER-04); **koreksi periode tertutup** diposting di periode terbuka dengan `correction_of_document_id`, tanggal asli, alasan, bukti dan approver kedua (BR-PER-02); **reopen periode** hanya lewat permintaan CSA + approver independen, tercatat sebagai kejadian luar biasa (BR-PER-03). Ketiganya juga ada di skrip UAT M3.
6. Rebuild proyeksi saldo dari ledger menghasilkan angka yang sama (BR-INV-11); daftar saldo dan ledger ribuan baris memakai index (EXPLAIN, D-093).
7. Isolasi tenant dan scope plant untuk semua tabel baru (RLS, composite FK); OpenAPI + typed client tanpa drift; migrate round-trip.
8. Gate frontend doc 22 §4 hijau + stack e2e M3 (terima → transfer → split → adjustment dua orang → reversal dua orang → saldo cocok).
9. Dokumen ter-update (docs 04, 13, 15, 17, 18, 24, ADR-0014) dan **owner menjalankan `docs/uat/M3-UAT.md`**.

## 5. Cara kerja
- Branch per langkah (`feat/m3-…`), PR berurutan mengikuti §1; CI hijau + review Codex bersih sebelum merge; tanpa auto-merge.
- Berhenti bila ada temuan keamanan atau keputusan bisnis baru; tidak mengisi keputusan bisnis sendiri.

**Pilihan owner:** ☑ Setuju scope M3 · ☑ Keputusan M3-D1..D10 sesuai usulan (2026-10-29)

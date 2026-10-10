# 27 — M0 Decision Sheet (lembar keputusan owner)

**Status:** PROPOSAL, untuk diputuskan owner. Belum ada keputusan yang berlaku sampai owner menjawab. Setelah disetujui, setiap keputusan dicatat di `13-DECISION-REGISTER.md` (D-0xx) dan dokumen terkait di-update.
**Tanggal:** 2026-10-11 · **Basis:** `main` @ `a103e24`; PoC D-B1-24 (`poc/d-b1-24-signed-context` @ `c806f57`, `poc/d-b1-24-optimization` @ `34949ba`).
**Tujuan:** menutup semua keputusan yang memblokir milestone M1–M5 dalam satu sesi, supaya implementasi bisa berjalan tanpa berhenti di tengah slice.

**Aturan lembar ini**
- **Alur bisnis tidak diubah.** Bagian 1 hanya merangkum apa yang sudah tertulis di dokumen kanonik. Tidak ada aturan bisnis, formula atau status baru.
- Kolom "Rekomendasi" memakai *proposed default* yang **sudah tercatat** di dokumen. Bila dokumen tidak punya usulan, rekomendasi ditandai **(usulan baru)** beserta alasannya.
- Cara menjawab: **"Setuju semua rekomendasi kecuali: …"**, atau isi per baris.

---

## 1. Baseline alur bisnis (ditetapkan dari dokumen yang ada — bukan keputusan baru)

### 1.1 Alur utama (doc 01, doc 00 acceptance gate, doc 21 R1 DoD)
```
Customer PO ──map──▶ Sales Order line (identitas demand tunggal, D-034)
                        └─ Delivery Schedule Lines / Customer Releases (anak SO line; blanket → D-039)
                              │  forecast dikonsumsi dalam bucket (D-040); tidak ada double count
                              ▼
PPIC: basic MPS (netting mingguan ISO Sen–Min, tanggal harian, per plant — D-041/D-044/D-046)
      → planned order (lot-for-lot, due date = due date harian paling awal) → WO release
      (snapshot BOM/routing/konversi; WO manual boleh dengan alasan + approval — G-MPS-6)
                              ▼
Materials: RM receipt (lot coil/sheet; UNRESTRICTED atau BLOCKED → O-013)
      → issue/transfer ke Material at Production (masih stok, belum terpakai)
                              ▼
Production: run per WO (mesin/dies/shift/lot) → posting konsumsi aktual terukur, remnant/return,
      Good / NG Rework / NG Rejected / Scrap → output ke Pending QC
                              ▼
Quality: inspeksi parsial → release / hold / reject / rework / scrap (SoD-01: pelapor ≠ releaser)
                              ▼
FG Warehouse: FG receipt hanya dari qty yang sudah QC-released → putaway → reserve → pick
                              ▼
Delivery: shipment (boleh parsial) terhadap SO line / release → outstanding ter-update
                              ▼
Reconciliation: issued vs consumed vs returned vs remnant vs scrap, teoretis vs aktual (GYV/PvS/UMV)
      → selisih yang tidak terjelaskan = case investigasi (contoh 25 pcs), bukan "hilang"
```

### 1.2 Invarian yang tidak boleh dilanggar (CLAUDE.md, doc 00 Core truths)
- **Inventory ledger satu-satunya sumber stok.** Semua perubahan lewat posting service; modul lain tidak menyimpan total stok sendiri.
- **Tidak ada modul/menu/label WIP.** Yang dipakai: **Material at Production / Semi-Finished Material**, dengan saldo fisik antar-tahap dan genealogy.
- **Konsep berbeda tidak boleh dicampur:** issued, reserved, physically present, consumed, scrap, returned, Pending QC, released FG, delivered.
- **Pending QC bukan supply** dan tidak pernah di-netting atau dijanjikan (D-043).
- **Record yang sudah diposting** dikoreksi lewat reversal/adjustment, tidak di-hard-delete. Koreksi sensitif butuh approval independen.
- **Tenant dan scope** dipaksakan di server (RLS + policy). Outbox dipakai untuk notifikasi lintas modul.

### 1.3 Domain data demo untuk testing owner (usulan baru — M-03)
Dokumen paling banyak memakai contoh **stamping dari coil/sheet → welding → assembly** (doc 02 contoh Sheet A/B, doc 26 §3A `PART-A-BLK/BND`, `WC-WELD-2`). Data demo akan memakai domain ini: 1 tenant, 1 plant, 2–3 customer, 5–10 part, coil dan sheet, 3 work center. Data demo sepenuhnya sintetis.

---

## 2. Keputusan metode (usulan baru, hasil diskusi 2026-10-11)

| ID | Keputusan | Rekomendasi | Pilihan owner |
|---|---|---|---|
| M-01 | Roadmap milestone | **M0** keputusan → **M1** akses (B1) → **M2** master data inti (B2) → **M3** ledger (B3) → **M4** alur tipis end-to-end (B4/B6/B7 tipis, WO manual) → **M5** bisa dipakai (basic MPS/MRP B5, rekonsiliasi B8 dasar, Control Tower data asli B9 sebagian, data demo) → **M6** staging online → **M7+** fitur R1 lanjutan dan hardening (B10). **Scope R1 (D-035) tidak berubah**; hanya urutan pengerjaannya. | ☐ Setuju ☐ Ubah |
| M-02 | Tata kelola per milestone | Satu approval per milestone (scope + acceptance criteria, 1 halaman), lalu implementasi sampai test hijau. Review owner lewat **demo berjalan**. Dokumen kanonik di-update dalam PR yang sama dengan kodenya. PoC hanya untuk risiko yang belum diketahui, maksimal 1 sesi. **Tetap berlaku:** feature branch + PR, CI hijau, secret scan, tanpa auto-merge, push atas instruksi owner. | ☐ Setuju ☐ Ubah |
| M-03 | Domain data demo | Stamping coil/sheet → welding → assembly (§1.3) | ☐ Setuju ☐ Ubah |
| M-04 | Testing oleh owner | Setiap milestone menyertakan seeder skenario yang bisa di-reset, plus skrip UAT berbasis doc 14 (langkah, hasil yang diharapkan) | ☐ Setuju ☐ Ubah |

---

## 3. Keputusan keamanan dan B1 (memblokir M1)

### 3.1 D-B1-24 — signed tenant context (hasil PoC)
| Opsi | Isi | Konsekuensi |
|---|---|---|
| **1 (rekomendasi)** | Terima **Option B-opt** (`ctx_api.ctx_v2`, BEGIN digabung dengan binding read) dengan **anggaran absolut** menggantikan kriteria 2 %: ≤ 0,3 ms p95 per transaksi dan ≤ 15 µs per referensi tabel ber-RLS di level DB, dijaga gate regresi di CI | Pemalsuan dan replay context tertutup (PoC-CTX-01/02 PASS). Overhead terukur +0,16–0,27 ms p95 (W-01/W-02), sedangkan W-03 dalam batas noise. Membuka D-B2-15 (approval routing). |
| 2 | Pertahankan kriteria 2 % dan pakai Option A | Kelemahan pemalsuan context terbukti (PoC-CTX-01); keamanan bergantung penuh pada disiplin query berparameter |
| 3 | PoC C extension (trusted per-transaction context) | Mungkin mendekati 2 %, tapi menambah build, upgrade dan beban operasional; M1 tertunda |

Pilihan owner: ☐ 1 ☐ 2 ☐ 3

### 3.2 Temuan PoC yang siap dijadikan keputusan (berlaku apa pun pilihan 3.1)
| ID | Keputusan | Rekomendasi | Pilihan |
|---|---|---|---|
| P-01 | Label parallel verifier | `PARALLEL RESTRICTED` wajib (PoC-CTX-05b: wrapper SAFE di dalam worker melihat pid berbeda). Koreksi doc 25 §5.5 yang menulis "PARALLEL SAFE where allowed". | ☐ |
| P-02 | Privilege pemilik tabel | Role pemilik tabel butuh `USAGE` (tanpa `EXECUTE`) pada schema `ctx_api` untuk membuat policy | ☐ |
| P-03 | Logging parameter | `log_parameter_max_length = 0` dan `…_on_error = 0` per role runtime | ☐ |
| P-04 | Allowlist definer | Tes CI: daftar fungsi `SECURITY DEFINER` harus sama persis dengan allowlist yang sudah direview (terbukti menangkap definer liar di PoC) | ☐ |
| P-05 | Jalur prepared statement (D-B1-09) | **Image `apps/api` tetap bookworm, tapi libpq dari apt.postgresql.org (≥ 17)** supaya native prepares lewat PgBouncer jalan (PoC-CTX-13 PASS). Alternatif: `PDO::PGSQL_ATTR_DISABLE_PREPARES`, yang juga PASS tapi menjadi amandemen D-B1-09. CI ditambah langkah yang mencetak dan memverifikasi versi libpq. | ☐ PGDG ☐ DISABLE_PREPARES |
| P-06 | Provisioning password role DB | Pakai verifier SCRAM yang sudah di-hash, atau `pg_stat_statements.track_utility = off` di sesi provisioning. Akses `pg_read_all_stats` diperlakukan sensitif. (Temuan: `CREATE ROLE … PASSWORD` tersimpan sebagai plaintext di `pg_stat_statements`.) | ☐ |

### 3.3 Keputusan wajib B1 (doc 25 §17 A) — rekomendasi = isi doc 25 v17
| ID | Ringkas | Rekomendasi doc 25 | Pilihan |
|---|---|---|---|
| D-B1-01 | Role DB | 7 role sesuai §5.1 | ☐ |
| D-B1-02 | Kolom scope | Kolom typed nullable + CHECK + composite FK | ☐ |
| D-B1-03 | Identitas global | `auth.identities` + fungsi definer §2.1/§2.2 | ☐ |
| D-B1-04 | Fitur auth | B1: login, logout, me, picker, plant switch, undangan, reset password; **TOTP MFA untuk role privileged di B1.1 sebelum pilot** | ☐ |
| D-B1-08 | Kode error | `CSRF_TOKEN_MISMATCH` (419), `SESSION_EXPIRED`, `SCOPE_FORBIDDEN` | ☐ |
| D-B1-09 | Pooler | Native prepares lewat PgBouncer ≥ 1.21 dengan `max_prepared_statements` (lihat P-05) | ☐ |
| D-B1-10 | Id | UUIDv7 untuk row tenant; UUIDv4 acak untuk user id global | ☐ |
| D-B1-13 | Provisioning tenant | Artisan command, langkah idempotent §2.5 | ☐ |
| D-B1-14 | Kalender bisnis di B1 | Ya | ☐ |
| D-B1-15 | Test harness | Grup A/B/C §11 | ☐ |
| D-B1-16 | Role admin awal | `TENANT_ADMIN` = `core.users_roles` (VCP) + `core.audit` (V) saja | ☐ |
| D-B1-18 | Jalur pengiriman secret | Worker delivery terpisah; dev pakai mailpit; **provider SMTP/API produksi diputuskan di M6** | ☐ |
| D-B1-19 | Aktivasi membership | ACTIVE saat undangan dari admin diterima | ☐ |
| D-B1-20 | Durabilitas security event | Flush setelah transaksi + log + retry job | ☐ |
| D-B1-23 | Ganti password di B1 | Lewat alur reset yang dikonfirmasi email; ganti langsung hadir di B1.1 dengan step-up | ☐ |
| D-B1-23b | Lokasi verifikasi password | **(a)** argon2id di PHP (rekomendasi doc 25) vs (b) bcrypt di DB | ☐ a ☐ b |
| D-B1-25 | Klasifikasi privileged untuk notifikasi reset | Sesuai doc 25 (permission sensitif efektif; penerima = admin tenant ∪ pemilik akun) | ☐ |

### 3.4 Parameter operasional B1 (doc 25 §17 B) — rekomendasi = angka doc 25
| ID | Parameter | Rekomendasi | Pilihan |
|---|---|---|---|
| D-B1-05 | Sesi | Kantor: idle 30 menit, absolut 12 jam. Perangkat lantai produksi: idle 15 menit, absolut = shift. Maksimal 24 jam. | ☐ |
| D-B1-06 | Batas login | 5/menit per akun, 20/menit per IP, 60/menit untuk NAT pabrik yang di-allowlist; lockout 15 menit setelah 10 gagal | ☐ |
| D-B1-07 | Password | argon2id (64 MiB, t = 3, p = 1); panjang minimal 12; daftar password bocor offline | ☐ |
| D-B1-11 | Timeout DB | Statement 5 s baca / 10 s command / 120 s job; lock 3 s; idle-in-transaction 30 s | ☐ |
| D-B1-21 | TTL dan throttle secret | Undangan 72 jam; reset 30 menit; ≤ 3 permintaan reset per jam; dan seterusnya sesuai doc 25 | ☐ |
| D-B1-22 | Kunci pepper/HMAC | 256-bit dari secrets manager, rotasi tahunan | ☐ |
| D-B1-12 | Retensi audit | **Ditunda** sampai sebelum kontrak pilot (V1 tidak menghapus apa pun) | ☐ |

---

## 4. Keputusan master data B2 (memblokir M2) — rekomendasi = doc 26 v13
| ID | Ringkas | Rekomendasi doc 26 | Pilihan |
|---|---|---|---|
| D-B2-05 | Scope routing | Per item + plant | ☐ |
| D-B2-08 | Overlap tanggal efektif | Exclusion constraint (`btree_gist`) | ☐ |
| D-B2-09 | Pencarian | `pg_trgm` GIN + B-tree prefix | ☐ |
| D-B2-10 | Basis cycle time | `cycle_basis` + `parts_per_cycle` | ☐ |
| D-B2-01 | Titik konsumsi material | **(a)** map BOM–routing yang disetujui per pasangan versi | ☐ a ☐ b ☐ c |
| D-B2-02 | Level yield standar | Per versi routing (formula PvS doc 02) | ☐ |
| D-B2-04 | Routing alternatif di V1 | Diizinkan, dipilih saat WO release (butuh D-B2-01 a) | ☐ |
| D-B2-06 | Approval routing/BOM/map | Maker ≠ checker; role approver dari O-007 | ☐ |
| D-B2-07 | Commit parsial saat import | All-or-nothing untuk routing/BOM/map; hanya baris valid untuk item, customer, customer part | ☐ |
| D-B2-12 | Keunikan posting produksi | Unique key `(tenant, run, production_date, shift)` untuk posting reguler; koreksi dan reversal dikecualikan. *(Ini aturan kanonik baru.)* | ☐ |
| D-B2-14 | Efektivitas routing mundur | **Tidak diizinkan di V1**; koreksi lewat versi baru mulai hari ini | ☐ |
| D-B2-15 | Gate approval routing | Terbuka otomatis bila 3.1 = opsi 1 | (ikut 3.1) |
| D-B2-11 | Proses subcontract | Bukan scope V1 | ☐ |

---

## 5. Keputusan OPEN bisnis (memblokir M3–M5)
| ID | Pertanyaan | Opsi | Rekomendasi | Dasar | Dibutuhkan di | Pilihan |
|---|---|---|---|---|---|---|
| **O-003** | Tahap antara (stamping → welding → assembly) | **S:** satu item, tahap = operasi routing, tidak ada stok setengah jadi · **M:** item Semi-Finished per tahap, ada stok fisik dan lot per tahap | **M** sebagai default, S boleh per item untuk routing in-line yang pendek (B2 mendukung keduanya) | CLAUDE.md mewajibkan *saldo fisik antar-tahap dan genealogy*. Opsi S tidak punya stok setengah jadi, jadi bertentangan dengan invarian ini. Penamaan item: `<PART>-<STAGE>` (contoh `PART-A-BLK`) | M4 (B5/B6) | ☐ M (+S per item) ☐ S ☐ M saja |
| **O-007** | Threshold approval, isi template role, akses support vendor | — | **(a)** Template role = doc 18 §1 apa adanya. **(b)** Tidak ada threshold kuantitas di V1 awal: **setiap aksi sensitif** (reversal, adjustment, QC release di atas policy, approval versi) butuh approver kedua, sesuai fallback yang sudah tercatat di doc 13. Threshold menjadi konfigurasi tenant dan diisi nanti. **(c)** *(usulan baru)* Impersonation support vendor **tidak ada di V1**; support hanya lewat membership sementara yang diberikan admin tenant, dengan audit. | doc 18, doc 13 tabel dependency | M1 (role), M3 (reversal), M4 (QC) | ☐ a ☐ b ☐ c |
| **O-012** | Toleransi variance default bila tenant belum mengatur | strict / persentase default | **Strict:** variance final yang tidak nol dan tidak terjelaskan membuka case | usulan di doc 13 | M5 (B8) | ☐ |
| **O-013** | Inspeksi RM masuk | Minimal (BLOCKED + release manual) / inspeksi penuh | **Minimal:** item yang ditandai wajib release masuk sebagai `BLOCKED`, lalu di-release manual (BR-ISS-06) | usulan di doc 13, doc 21 E-08 | M4 (B6) | ☐ |
| **O-015** | Sisa Material at Production saat WO close | — | WO close diblok sampai sisa material **di-return**, **dipindah ke alokasi WO lain**, atau **ditutup dengan exception yang di-approve** (BR-PLN-03) | usulan di doc 13 | M4/M5 (B5/B6) | ☐ |
| O-004 (sisa) | Custody barang REJECTED/HOLD | (a) tetap di QC Area di bawah custody Production · (b) lokasi quarantine terpisah di bawah custody Quality | *(usulan baru)* **(b)** per tenant (opsional): barang reject dan hold jelas terpisah dari barang menunggu inspeksi | D-014/DD-01 | M4 (B7) | ☐ a ☐ b |
| O-021 | Validasi aksesibilitas dan CI di runner nyata | — | **Tetap OPEN** sampai rilis pilot. CI GitHub sudah hijau sejak B0; sisanya validasi screen reader dan perangkat. | doc 22 §6 | Pilot | (tetap OPEN) |

---

## 6. Ditunda (bukan blocker M1–M5) — dicatat dengan tenggat
| ID | Topik | Tenggat |
|---|---|---|
| O-008 | Hosting, region, residensi data, SLO/RPO/RTO | Sebelum M6 (staging online) |
| D-B1-18 (provider) | Provider email produksi | M6 |
| O-001 | Data konversi coil/sheet aktual per item | Saat ada item pilot nyata; demo memakai data sintetis |
| O-002 (sisa) | Penjualan salvage/recycle; toleransi default per proses | M7 |
| O-005 | Multi-plant, subcontract, consignment, retur | M7 (MAT_TRANSFER sudah ada lewat D-048) |
| O-006 | Stock count: freeze vs snapshot; shift lewat tengah malam | M7 (stock count) |
| O-009 | Migrasi data pilot, standar label barcode/QR, matriks perangkat, cakupan offline | Sebelum pilot |
| O-010 | Logo/brand, watermark PDF | Sebelum fitur PDF |
| O-014 (sisa) | Data Correction Center, step-up MFA, handling unit, dll. | Sesuai usulan di doc 13; dikonfirmasi per milestone M7 |
| D-B1-12 | Retensi audit | Sebelum kontrak pilot |

---

## 7. Setelah owner menjawab
1. Keputusan dicatat di doc 13 (D-0xx) dan diterapkan ke doc 25, 26, 15, 18 dan CLAUDE.md (koreksi P-01 dan seterusnya) dalam satu PR dokumentasi.
2. Scope dan acceptance M1 (1 halaman) diajukan untuk approval.
3. Branch PoC tetap lokal. Teardown resource Docker PoC hanya dengan konfirmasi terpisah.

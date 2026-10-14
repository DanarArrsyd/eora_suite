# M1 UAT — Access (login, workspace, users, roles, reset, notifications)

**Untuk:** owner (demo M1, doc 28 §3 butir 8). **Data:** tenant sintetis "Demo Stamping" dari `eora:demo:seed`; semua nama, e-mail (`*.test`) dan isinya fiktif. **Browser:** Chrome, Edge atau Firefox di `http://localhost:3100` (bukan alamat IP; Safari belum bisa — cookie `__Host-`/`Secure` di http lokal).

Isi kolom **Hasil** dengan OK / Tidak OK + catatan singkat. Modul lain (PPIC, Materials, dst.) masih berlabel **Mock data** dan bukan bagian UAT ini.

## 0. Persiapan (sekali)
1. Nyalakan backend di root repo:
   ```bash
   docker compose up -d --wait
   ```
2. Buat data demo. Catat password yang dicetak — hanya tampil sekali:
   ```bash
   docker compose run --rm --entrypoint php db-migrate artisan eora:demo:seed
   ```
   Kalau muncul "already exists": data demo sudah ada. Reset = database lokal baru (`docker compose down -v`, lalu langkah 1–2 lagi), atau buat tenant demo kedua dengan `--code=demo-stamping-2`.
3. Jalankan web:
   ```bash
   npx pnpm@12.10.1 --filter @eora/web dev
   ```
4. Mailpit (kotak surat lokal untuk undangan dan reset): `http://localhost:8025`.

## 1. Skenario

| # | Langkah | Yang diharapkan | Hasil |
|---|---|---|---|
| 1 | Buka `http://localhost:3100`. | Diarahkan ke **Sign in**. | |
| 2 | Login `tenant-admin@demo-stamping.test` dengan password salah. | Satu pesan umum: e-mail atau password salah / akun terkunci sementara. Tidak menyebut mana yang salah. | |
| 3 | Login dengan password demo yang benar. | Masuk langsung ke workspace (hanya 1 tenant, tanpa picker). Topbar: **Demo Stamping**. | |
| 4 | Lihat pemilih workspace di topbar (plant satu-satunya langsung aktif, D-071; kalau ada P2 dari `eora:demo:plant`, pilih plant sendiri). | Topbar menampilkan plant **P1 · Plant 1 (demo)**. | |
| 5 | Buka **Administration → Users**. | 14 user, semua **Active**, kolom role berisi role + scope (mis. `QCI · Plant P1 · Plant 1 (demo)`). | |
| 6 | Klik **Invite user**, isi `uat.baru@demo-stamping.test`, kirim. | Pesan "Invitation sent…". User baru muncul di tab **Invited**, kolom role "No role". | |
| 7 | Buka Mailpit, buka e-mail undangan, klik link. Set password baru (min. 12 karakter). | "Your account is ready". | |
| 8 | Kembali sebagai admin → **Users** → **Manage** user baru → **Assign role**: QCI, scope One plant (suggested), plant P1, alasan "UAT". | Role muncul di daftar role user; status user **Active**. | |
| 9 | **Revoke** role tadi, isi alasan. | Konfirmasi meminta alasan; role hilang dari daftar. | |
| 10 | Buka **Manage** pada akun Anda sendiri (tenant-admin). | Tombol Suspend / Revoke nonaktif dengan alasan "Your own access can be changed only by another administrator." | |
| 11 | **Suspend** user `uat.baru…` (konfirmasi), lalu **Reactivate**. | Status berubah Suspended → Active; tab hitungannya ikut berubah. | |
| 12 | Buka **Roles & Permissions**, buka role **QCI**. | Daftar permission per modul; pemegang role di scope Anda. Role APR/MAD tertulis "None yet". | |
| 13 | Logout (menu akun → Sign out). Login sebagai `aud@demo-stamping.test`. | Menu **Administration → Users** terlihat (hak lihat), tetapi Invite / Suspend / Assign nonaktif dengan alasan izin. | |
| 14 | Logout. Login sebagai `qci@demo-stamping.test`. | Menu **Administration** tidak tampil. Pemilih workspace hanya berisi plant dalam scope (P1). | |
| 15 | Logout. Di halaman Sign in klik **Forgot your password?**, isi `tenant-admin@demo-stamping.test`. | Selalu jawaban yang sama ("cek e-mail"), juga untuk e-mail yang tidak terdaftar. | |
| 16 | Buka e-mail reset di Mailpit, set password baru. | Password lama tidak bisa dipakai lagi; password baru bisa. | |
| 17 | Login lagi sebagai tenant-admin, buka lonceng / **Notifications**. | Notifikasi **Password reset on a privileged account** (label Warning). **Mark as read** menghapus titik biru di lonceng. | |
| 18 | Tekan **Ctrl/⌘ K**, ketik `users`, Enter. | Halaman Users terbuka. | |
| 19 | Sign out. Buka `http://localhost:3100/admin/users` langsung. | Diarahkan ke Sign in; setelah login kembali ke halaman Users. | |

## 2. Keputusan owner
- [ ] **M1 OK** — semua skenario OK (atau catatan non-blocking di bawah).
- [ ] **Perlu perbaikan** — tulis nomor skenario dan catatannya.

Catatan:

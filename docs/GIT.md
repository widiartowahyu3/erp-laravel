# Kebijakan & Alur Kerja Git (Git Workflow)

Dokumen ini mengatur standar pengelolaan kode, keamanan cabang (branching model), dan perlindungan rahasia pada proyek ERP Distribusi / Trading.

---

## 1. Alur Kerja Kontrol Versi & Cabang (Workflow & Tagging Model)

Praktik aktual pengembangan proyek ERP ini menerapkan model **Trunk-Based Development** terarah dengan penandaan versi lokal (*Local Git Tagging*) yang ketat:

1. **Cabang Tunggal (`main`)**:
   - Seluruh pekerjaan fase dikembangkan dan diuji secara inkremental pada cabang `main`.
   - **Satu butir = satu commit** menggunakan format Conventional Commits. Dilarang menggabungkan beberapa butir pekerjaan dalam satu commit.
   - Dilarang keras melakukan `git push` oleh agen/pelaksana; proses push ke remote hanya dilakukan oleh pemilik (*owner*).
   - Dilarang `--force`, `--no-verify`, menulis ulang riwayat git (*history rewriting*), atau mengubah konfigurasi agent/SSH.

2. **Penandaan Rilis Fase (*Git Tagging*)**:
   - Setiap penyelesaian gerbang verifikasi fase ditandai dengan Git Tag lokal permanen:
     - `fase-0`: Fondasi infrastruktur container Docker & hardening dasar.
     - `fase-1`: Lapisan keamanan web server & runtime PHP-FPM.
     - `fase-2`: Desain database MariaDB 12.3 & trigger append-only.
     - `fase-3`: Fondasi backend Laravel 13, strict mode, & Decimal math.
     - `fase-4`: Fondasi autentikasi, sesi terisolasi, & audit logs.
     - `fase-5`: Design system Vue 3 + Inertia + Tailwind 4 & WCAG 2.1 AA.
     - `fase-6`: Modul Master Data (8 entitas) & Reference Guard.
     - `fase-7`: Modul Pembelian & Inventori (PO, GR, Kartu Stok, Penyesuaian Stok).
     - `fase-7.1`: Koreksi audit fungsional Fase 7.
     - `fase-7.5a`: Penutupan celah kualitas & keamanan (Bagian A).
     - `fase-7.5`: Kesiapan operasional, benchmark performa, dashboard, & CI (Bagian B).
   - Tag lokal dibuat hanya setelah seluruh gerbang pengujian (`make check`, `make test`, `make verify`) berstatus PASS 100%.

3. **Format Pesan Commit (Conventional Commits)**:
   - Wajib mengikuti format baku:
     ```text
     <type>(<scope>): <keterangan singkat dalam bahasa Inggris>
     ```
   - Tipe yang diperbolehkan: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`, `build`, `perf`, `style`.
   - Contoh: `chore(docker): add hardened php-fpm multi-stage image`.
   - Penegakan format divalidasi secara otomatis oleh hook `.githooks/commit-msg`.

---

## 2. Pengamanan Rahasia & Hooks

Repositori ini mengonfigurasi Git Hooks pada direktori `.githooks`:
- **`pre-commit`**:
  - Memblokir staging file `.env` atau variasinya (kecuali `.env.example` dan `.env.production.example`).
  - Memblokir staging berkas sertifikat dan kunci kriptografi (`*.pem`, `*.key`, `*.crt`, `id_rsa*`, `id_ed25519*`).
  - Memeriksa isi diff untuk mendeteksi `APP_KEY=base64:` dan kata sandi hardcoded.
  - Menjalankan pemindaian `gitleaks` berbasis container resmi Docker pada staged changes.
- **`commit-msg`**:
  - Memvalidasi kepatuhan format Conventional Commits sebelum commit dicatat.

Aktivasi hooks di lingkungan lokal:
```bash
git config core.hooksPath .githooks
```

---

## 3. Checklist Manual Keamanan Repositori (Tindakan Pemilik)

Pemilik repositori (Repository Owner) wajib memastikan konfigurasi keamanan berikut aktif di penyedia Git (GitHub):

- [ ] **Visibilitas Repositori Private**: Pastikan repositori berstatus **Private**.
- [ ] **Autentikasi Dua Faktor (2FA)**: Seluruh akun kontributor wajib mengaktifkan 2FA.
- [ ] **Branch Protection Rules pada `main`**:
  - Buka *Settings* -> *Branches* -> *Add branch ruleset* / *Branch protection rule* untuk `main`.
  - Aktifkan *Require a pull request before merging*.
  - Aktifkan *Require status checks to pass before merging*.
  - Aktifkan *Do not allow bypassing the above settings* (bahkan untuk admin).
- [ ] **Secret Scanning & Push Protection**:
  - Buka *Settings* -> *Code security and analysis*.
  - Aktifkan *Secret scanning* dan *Push protection* untuk mencegah secret terunggah ke remote.
- [ ] **Dependabot Alerts & Security Updates**:
  - Aktifkan *Dependabot alerts* untuk memantau kerentanan dependensi secara berkala.

# Kebijakan & Alur Kerja Git (Git Workflow)

Dokumen ini mengatur standar pengelolaan kode, keamanan cabang (branching model), dan perlindungan rahasia pada proyek ERP Distribusi / Trading.

---

## 1. Alur Kerja Cabang (Branching Workflow)

1. **Cabang Utama (`main`)**:
   - Cabang `main` merepresentasikan status lingkungan yang stabil, terverifikasi, dan siap rilis.
   - Tidak diperkenankan melakukan push langsung tanpa verifikasi.
   - Setiap penyelesaian fase wajib diberi Git Tag penanda rilis (mis. `fase-0`, `fase-1`).

2. **Cabang Pengembangan Fase (`fase/xx-nama-fitur`)**:
   - Setiap pengerjaan fase atau modul baru wajib dibuat dalam cabang terpisah:
     ```bash
     git checkout -b fase/00-environment-hardening
     ```
   - Merge ke `main` hanya boleh dilakukan setelah seluruh skrip verifikasi otomatis (mis. `make verify`) berstatus **PASS 100%**.

3. **Format Pesan Commit (Conventional Commits)**:
   - Wajib mengikuti format:
     ```text
     <type>(<scope>): <keterangan singkat dalam bahasa Inggris>
     ```
   - Tipe yang diperbolehkan: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`, `build`, `perf`, `style`.
   - Contoh: `chore(docker): add hardened php-fpm multi-stage image`.

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

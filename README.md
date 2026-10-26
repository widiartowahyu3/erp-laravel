# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 3 - Autentikasi, Otorisasi RBAC, Audit Logging, & Sesi Hardened (SELESAI & LULUS VERIFIKASI)

Fase 3 telah selesai mengimplementasikan sistem autentikasi, otorisasi peran (RBAC), audit logging keamanan, dan manajemen sesi yang aman di atas stack monolitik Laravel 13 + Vue 3:
- **Autentikasi Native Terproteksi**:
  - Hashing kata sandi berbasis Argon2id (`HASH_DRIVER=argon2id`).
  - Halaman login (`/login`) dan ganti kata sandi (`/account/password`) reaktif dengan Inertia Vue 3.
  - Pembatasan laju (Rate Limiting) berbasis database (5 percobaan gagal/menit per email+IP, percobaan ke-6 HTTP 429).
  - Pesan kesalahan seragam untuk mencegah *account enumeration*.
- **Otorisasi Granular RBAC**:
  - Matriks hak akses lengkap: tepat 98 permissions dan 7 system roles (`super_admin`, `admin`, `purchasing`, `warehouse`, `sales`, `finance`, `viewer`).
  - Evaluasi hak akses via `Gate::before` terintegrasi in-memory caching pada model `User` bebas masalah N+1 query.
  - Menu navigasi dinamis yang memfilter tautan berdasarkan hak akses pengguna secara otomatis.
- **Audit Logging Keamanan Persisten**:
  - Layanan `AuditLogger` mencatat peristiwa `auth.login.success`, `auth.login.failed`, `auth.logout.success`, `auth.session.expired`, `auth.user.deactivated`, `auth.password.changed`, dan `access.denied` (403).
  - Sanitasi rekursif data rahasia (`/password|token|secret|key/i`) dan hashing SHA-256 pada raw email.
  - Tabel `audit_logs` dilindungi trigger MariaDB integritas *append-only*.
- **Hardening Sesi**:
  - Penyimpanan sesi di database, atribut cookie `HttpOnly` dan `SameSite=lax`.
  - *Idle timeout* 120 menit dan *Absolute lifetime* 480 menit (8 jam).
  - Middleware `EnsureUserIsActive` memaksa logout seketika jika akun dinonaktifkan di tengah sesi aktif.
- **Suite Pengujian & Verifikasi**:
  - Pest test suite: **120 tests / 1275 assertions** lulus 100%.
  - Larastan Level 6: 0 error.
  - Laravel Pint: 0 pelanggaran.
  - ESLint & Prettier: 0 error.
  - Audit dependensi: 0 vulnerabilities (`composer audit` & `npm audit`).
  - Suite verifikasi hardening: **110 PASS / 0 FAIL** (`make verify`).

---

## Panduan Setup Lingkungan & Alur Kerja Pengembang (Quickstart)

### 1. Prasyarat & Git Hooks
Pastikan repositori berada di dalam filesystem Linux native WSL (mis. `~/projects/erp-trading`):
```bash
git clone git@github.com:widiartowahyu3/erp-laravel.git erp-trading
cd erp-trading

# Pasang hook keamanan Git lokal (.githooks: Gitleaks, regex secret detector, Conventional Commits)
make hooks
```

### 2. Inisialisasi Berkas Lingkungan & Rahasia
Siapkan konfigurasi infrastruktur dan aplikasi:
```bash
# 1. Berkas .env infrastruktur di root repositori
cp .env.example .env
APP_PASS=$(openssl rand -hex 24)
MIGRATOR_PASS=$(openssl rand -hex 24)
sed -i "s/change_this_to_a_secure_random_app_password/${APP_PASS}/" .env
sed -i "s/change_this_to_a_secure_random_migrator_password/${MIGRATOR_PASS}/" .env
chmod 600 .env

# 2. Berkas kata sandi root MariaDB terpisah (Docker Compose secret)
openssl rand -hex 24 > secrets/db_root_password.txt
chmod 644 secrets/db_root_password.txt
chmod 700 secrets

# 3. Berkas .env aplikasi Laravel di src/.env
cp src/.env.example src/.env
sed -i "s/APP_ENV=production/APP_ENV=local/" src/.env
sed -i "s/APP_DEBUG=false/APP_DEBUG=true/" src/.env
sed -i "s|APP_URL=https://erp.example.com|APP_URL=http://127.0.0.1:8080|" src/.env
sed -i "s/SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false/" src/.env
sed -i "s/DB_PASSWORD=change_this_to_erp_app_password/DB_PASSWORD=${APP_PASS}/" src/.env
chmod 600 src/.env
```

### 3. Menjalankan Docker Stack & Migrasi Basis Data
```bash
# Build image Docker
make build

# Jalankan stack layanan di latar belakang
make up

# Generate application key Laravel
make artisan cmd="key:generate"

# Jalankan migrasi basis data menggunakan koneksi terisolasi erp_migrator
make migrate

# Jalankan seeder permission dan peran sistem
make seed

# Buat akun Super Administrator pertama secara interaktif
make artisan cmd="erp:admin:create"

# Bangun aset frontend
docker compose --profile tools run --rm node npm run build
```

### 4. Menjalankan Development Server (HMR)
```bash
# Menjalankan Vite dev server untuk hot module replacement (HMR) di port 5174
make npm cmd="run dev"
```

Akses aplikasi pada peramban:
- **Aplikasi Web**: [http://127.0.0.1:8080](http://127.0.0.1:8080)
- **Halaman Login**: [http://127.0.0.1:8080/login](http://127.0.0.1:8080/login)
- **Vite Dev Server (HMR)**: [http://127.0.0.1:5174](http://127.0.0.1:5174)
- **Healthcheck Endpoint**: [http://127.0.0.1:8080/up](http://127.0.0.1:8080/up)

---

## Perintah Kualitas & Pengujian

Seluruh pengujian dan inspeksi kualitas dijalankan di dalam container:

```bash
# Menjalankan seluruh pemeriksaan kualitas (Pint + Larastan + Pest + Audit)
make check

# Menjalankan suite pengujian Pest (Unit, Feature, Arch)
make test

# Menjalankan pengecekan gaya kode PHP (Laravel Pint)
make lint

# Menjalankan analisis statis PHP (Larastan level 6)
make analyse

# Menjalankan linting frontend Vue/JS (ESLint)
docker compose --profile tools run --rm node npm run lint

# Menjalankan skrip verifikasi keamanan & hardening (110 butir uji)
make verify
```

---

## Dokumentasi Teknis

- [Daftar Stack & Matriks Versi Final](docs/STACK.md)
- [Arsitektur Autentikasi, RBAC, & Audit](docs/AUTH.md)
- [Arsitektur Monolith & Alur Sistem](docs/ARCHITECTURE.md)
- [Konvensi Struktur & Standar Koding](docs/CONVENTIONS.md)
- [Kebijakan Keamanan & Matriks Mitigasi](docs/SECURITY.md)
- [Panduan Operasional Docker & WSL](docs/DOCKER.md)
- [Kebijakan & Alur Kerja Git](docs/GIT.md)

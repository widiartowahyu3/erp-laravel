# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 1 - Baseline Fondasi & Keamanan Aplikasi (SELESAI & LULUS VERIFIKASI)

Fase 1 telah selesai mengimplementasikan fondasi skeleton aplikasi yang aman, teruji, dan siap pakai di atas infrastruktur Docker hardened Fase 0:
- **Backend Framework**: Laravel 13 (PHP 8.5 FPM unprivileged container), `declare(strict_types=1)`, `Model::shouldBeStrict()`, `Date::use(CarbonImmutable::class)`.
- **Database Relasional**: MariaDB 12.3 LTS dengan isolasi hak akses role aplikasi:
  - `mariadb` (`erp_app`): DML strictly (SELECT, INSERT, UPDATE, DELETE). Dilarang keras DDL di runtime.
  - `mariadb_migrator` (`erp_migrator`): DDL untuk menjalankan migrasi skema tabel via `make migrate`.
  - Bersih 100% dari jejak SQLite.
- **Frontend Modern**: Vue 3 + Inertia.js v2/v3 + Tailwind CSS v4 (menggunakan `@theme` token sistem desain), Lucide Icons, dan font Inter Variable self-hosted (tanpa Google Fonts/CDN eksternal).
- **Keamanan Aplikasi**:
  - Middleware `SecurityHeaders`: CSP ketat dengan per-request nonce sinkron dengan Vite (`Vite::useCspNonce()`), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `COOP`, serta `HSTS` & `upgrade-insecure-requests` otomatis di production.
  - Sesi aman terenkripsi dengan atribut `HttpOnly` dan `SameSite=lax`.
  - Penanganan error bersih tanpa membocorkan stack trace, query database, maupun environment di production.
  - Trusted proxies terkonfigurasi untuk reverse proxy Docker internal.
- **Tooling Kualitas & Pengujian Otomatis**:
  - Pest PHP: Suite pengujian fungsional database nyata (MariaDB), security headers, config safety, privilege denial DDL, dan architectural tests pelarangan fungsi berbahaya (`eval`, `exec`, `dd`, dsb).
  - Larastan Level 6: Analisis statis tanpa error.
  - Laravel Pint: Standar gaya koding strict PSR-12/Laravel.
  - ESLint & Prettier: Linting dan formatting komponen Vue 3.
  - Audit dependensi: 0 vulnerabilities pada `composer audit` dan `npm audit`.
  - Suite verifikasi hardening: **89 PASS / 0 FAIL** (`make verify`).

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

# Menjalankan skrip verifikasi keamanan & hardening (89 butir uji)
make verify
```

---

## Dokumentasi Teknis

- [Daftar Stack & Matriks Versi Final](docs/STACK.md)
- [Arsitektur Monolith & Alur Sistem](docs/ARCHITECTURE.md)
- [Konvensi Struktur & Standar Koding](docs/CONVENTIONS.md)
- [Kebijakan Keamanan & Matriks Mitigasi](docs/SECURITY.md)
- [Panduan Operasional Docker & WSL](docs/DOCKER.md)
- [Kebijakan & Alur Kerja Git](docs/GIT.md)

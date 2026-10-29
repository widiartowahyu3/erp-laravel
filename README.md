# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 6 - Master Data End-to-End & Reference Guards (SELESAI & LULUS VERIFIKASI)

Fase 6 telah menyelesaikan implementasi master data end-to-end (backend model, service, controller, request, rute, hingga antarmuka pengguna Vue 3/Inertia) untuk 8 entitas inti:
- **8 Entitas Master Data**:
  - `units` (Satuan Ukuran), `product_categories` (Kategori Produk bertingkat), `products` (Katalog Produk/Barang), `warehouses` (Gudang/Lokasi Stok), `suppliers` (Pemasok), `customers` (Pelanggan), `tax_rates` (Tarif Pajak), dan `payment_terms` (Syarat Pembayaran).
- **Integritas Relasional & Penjaga Referensi (`ReferenceGuard`)**:
  - Matriks pra-penghapusan pada `ReferenceGuard` memvalidasi ketergantungan rekaman di seluruh tabel transaksi dan master hilir.
  - Percobaan menghapus data yang sedang digunakan menghasilkan `BusinessRuleException` status **409 Conflict** dengan pesan Bahasa Indonesia yang jelas.
- **Pohon Hirarki Kategori Produk**:
  - Pencegahan pemilihan diri sendiri (*self-parent*) dan pencegahan siklus relasi (*cycle prevention*) pada turunan kategori.
  - Penegakan batas kedalaman pohon kategori maksimal 5 tingkat.
- **Keamanan Aritmetika Desimal & Aturan Unik Sadar Soft-Delete**:
  - Nilai moneter dan kuantitas divalidasi dengan rule `DecimalString` dan dioperasikan sebagai string murni tanpa konversi floating-point.
  - Validasi keunikan FormRequest mengabaikan rekaman soft-delete (`->whereNull('deleted_at')`) dan record yang sedang diedit (`->ignore($id)`).
- **Antarmuka Pengguna & Aksesibilitas**:
  - 32 komponen halaman master data terintegrasi (`Index`, `Create`, `Edit`, `Form` untuk 8 modul) dibangun menggunakan komponen Design System Fase 5.
  - Audit aksesibilitas otomatis axe-core: 0 temuan critical atau serious pada seluruh halaman form dan index master data.
- **Data Demo Realistis**:
  - Command `make seed-demo` (`DemoMasterDataSeeder`) mengisi data demo operasional perdagangan secara idempoten dengan perlindungan ketat fail-safe di lingkungan produksi.
- **Metrik Kualitas & Keamanan**:
  - Pest Test Suite: **326 passed / 2790 assertions** (0 failed).
  - Vitest Component & A11y Suite: **7 test files / 83 tests passed** (0 failed).
  - PHPStan Level 5: **0 errors** (95 files).
  - Laravel Pint: **202 files clean** (0 style issues).
  - Hardening Verification Suite: **127 PASS / 0 FAIL** (`make verify`).

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
- **Katalog Styleguide (DEV)**: [http://127.0.0.1:8080/dev/styleguide](http://127.0.0.1:8080/dev/styleguide) *(Khusus APP_ENV=local & terautentikasi)*
- **Vite Dev Server (HMR)**: [http://127.0.0.1:5174](http://127.0.0.1:5174)
- **Healthcheck Endpoint**: [http://127.0.0.1:8080/up](http://127.0.0.1:8080/up)

---

## Perintah Kualitas & Pengujian

Seluruh pengujian dan inspeksi kualitas dijalankan di dalam container:

```bash
# Menjalankan seluruh pemeriksaan kualitas (Pint + Larastan + Pest + Vitest)
make check

# Menjalankan suite pengujian Pest (Unit, Feature, Arch)
make test

# Menjalankan suite pengujian frontend Vitest & axe-core
make test-js

# Menjalankan pengecekan gaya kode PHP (Laravel Pint)
make lint

# Menjalankan analisis statis PHP (Larastan level 6)
make analyse

# Menjalankan linting frontend Vue/JS (ESLint)
docker compose --profile tools run --rm node npm run lint

# Menjalankan skrip verifikasi keamanan & hardening (122 butir uji)
make verify
```

---

## Dokumentasi Teknis

- [Daftar Stack & Matriks Versi Final](docs/STACK.md)
- [Design System & Panduan Komponen UI](docs/DESIGN_SYSTEM.md)
- [Konvensi & Aturan Frontend](docs/FRONTEND_CONVENTIONS.md)
- [Panduan Pemeriksaan Visual Peramban](docs/VISUAL_REVIEW.md)
- [Arsitektur Autentikasi, RBAC, & Audit](docs/AUTH.md)
- [Arsitektur Monolith & Alur Sistem](docs/ARCHITECTURE.md)
- [Konvensi Struktur & Standar Koding](docs/CONVENTIONS.md)
- [Kebijakan Keamanan & Matriks Mitigasi](docs/SECURITY.md)
- [Panduan Operasional Docker & WSL](docs/DOCKER.md)
- [Kebijakan & Alur Kerja Git](docs/GIT.md)

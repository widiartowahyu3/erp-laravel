# ERP Distribusi / Trading (Laravel + Vue Monolith)

[![CI](https://github.com/widiartowahyu3/erp-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/widiartowahyu3/erp-laravel/actions/workflows/ci.yml)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 7 - Pembelian dan Inventori (PO, GR, Kartu Stok, Penyesuaian Stok) (SELESAI)

Fase 7 telah menyelesaikan implementasi modul Pembelian dan Inventori secara end-to-end (backend model, service, kalkulator Moving Average Cost, controller, form request, locking konkurensi, hingga antarmuka pengguna Vue 3/Inertia):
- **Modul Inventori (Stock Management)**:
  - `stock_balances` (Snapshot saldo persediaan & biaya rata-rata bergerak).
  - `stock_movements` (Buku besar pergerakan inventori bersifat *APPEND-ONLY* dengan trigger database anti-mutasi).
  - `stock_adjustments` & `stock_adjustment_items` (Penyesuaian stok / stock opname dengan mutasi pembalik otomatis saat dibatalkan).
  - Halaman Antarmuka: Saldo Stok (`/inventory/stock-balances`), Mutasi Stok (`/inventory/stock-movements`), Kartu Stok (`/inventory/stock-card`), dan Penyesuaian Stok (`/inventory/stock-adjustments/*`).
- **Modul Pembelian (Purchasing & Procurement)**:
  - `purchase_orders` & `purchase_order_items` (Pesanan Pembelian dengan kalkulator P3: gross, net, tax, total; state machine dengan transisi rollback saat pembatalan penerimaan).
  - `goods_receipts` & `goods_receipt_items` (Surat Penerimaan Barang terikat PO; penambahan stok atomik dan locking deterministik PO -> GR -> Items -> Balances).
  - Halaman Antarmuka: Pesanan Pembelian (`/purchasing/purchase-orders/*`) dan Penerimaan Barang (`/purchasing/goods-receipts/*`).
- **Integritas Konkurensi & Keamanan**:
  - Nol floating point arithmetic di seluruh stack (PHP menggunakan `bcmath` via `App\Support\Decimal`, frontend menggunakan `BigInt` via `Support/decimal.js`).
  - Pemisahan peran ketat (*Separation of Duties*): peran `purchasing` menguasai PO & intip GR; peran `warehouse` menguasai GR & penyesuaian stok.
  - Rate limiting mutasi `throttle:erp-write` pada seluruh aksi POST/PUT transaksi.
  - Total permission sistem tetap terkunci di angka 98 (dijaga `ZGuardIntegrityTest`).
- **Verifikasi & Hardening**:
  - Hardening Verification Suite: 24 butir pengujian (`make verify`) mencakup 11 rute inventori dan 17 rute purchasing.

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

- [Modul Inventori & Saldo Stok](docs/INVENTORY.md)
- [Modul Pembelian & Penerimaan Barang](docs/PURCHASING.md)
- [Desain & Konvensi Database](docs/DATABASE_DESIGN.md)
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

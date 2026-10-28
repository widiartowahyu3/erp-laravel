# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 5 - Design System Frontend, Aksesibilitas WCAG & Kualitas Antarmuka (SELESAI & LULUS VERIFIKASI)

Fase 5 telah mengimplementasikan Design System terpadu, antarmuka responsif, dan gerbang kualitas frontend untuk stack Laravel 13 + Vue 3 + Inertia 3 + Tailwind CSS 4:
- **Arsitektur Token & Tema Semantik**:
  - Token CSS variables lapis ganda (`:root` dan `[data-theme="dark"]`) untuk permukaan, garis batas, teks, dan status aksi.
  - Verifikasi matematis rasio kontras WCAG 2.1 AA ($\ge 4.5:1$) pada 17 pasangan warna tema terang dan gelap (`theme-contrast.test.js`).
  - Pencegahan kedipan (*Anti-FOUC*) sinkron di Blade `<head>` menggunakan nonce CSP per-request.
- **Katalog Komponen Primitif & Komposit**:
  - 16 komponen primitif UI (`Button`, `IconButton`, `TextInput`, `PasswordInput`, `Textarea`, `Select`, `Checkbox`, `Switch`, `FormField`, `Badge`, `StatusBadge`, `Card`, `Alert`, `Spinner`, `Skeleton`, `Tooltip`).
  - 17 komponen komposit & formatter (`Modal`, `ConfirmDialog`, `Toast`, `FlashRegion`, `Breadcrumbs`, `PageHeader`, `EmptyState`, `ErrorState`, `LoadingState`, `Dropdown`, `DataTable`, `Pagination`, `FilterBar`, `Rupiah`, `Quantity`, `DateDisplay`, `PercentDisplay`).
  - Format angka dan mata uang strictly float-safe tanpa `parseFloat()` atau `Number()`.
  - Auto-resize textarea menggunakan utilitas CSS `field-sizing-content` murni tanpa injeksi inline style.
- **Layout Terpadu & Migrasi Halaman**:
  - `AppLayout.vue` dengan sidebar responsif per modul (Dashboard, Master Data, Pembelian, Penjualan, Inventori, Keuangan, Laporan, Administrasi), collapsible di desktop, drawer mobile ber-focus trap, dan topbar terintegrasi.
  - Halaman `Login.vue` dan `Password.vue` sepenuhnya termigrasi ke komponen UI design system tanpa regresi pada tes backend Pest.
  - Halaman `Dashboard.vue` dengan kisi modul berizin dan status lingkungan sistem.
  - Halaman katalog lokal `/dev/styleguide` yang dijaga ketat (`app()->environment('local')` dan middleware `auth`).
- **Gerbang Kualitas & Audit Aksesibilitas**:
  - Vitest + Vue Test Utils + JSDOM: **7 test files / 78 tests passed**.
  - Audit aksesibilitas otomatis axe-core: 0 temuan critical / serious pada seluruh komponen dan halaman styleguide.
  - Audit kepatuhan statis (`static-compliance.test.js`): 0 `v-html`, 0 static `style=`, 0 SFC `<style>`, 0 dependensi CDN luar.
  - Suite verifikasi hardening: **122 PASS / 0 FAIL** (`make verify`).

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

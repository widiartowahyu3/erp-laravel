# ERP Distribusi / Trading (Laravel 13 + Vue 3 Monolith)

[![CI](https://github.com/widiartowahyu3/erp-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/widiartowahyu3/erp-laravel/actions/workflows/ci.yml)

Repositori sistem ERP operasional distribusi dan trading berbasis arsitektur monolitik modern (**Laravel 13** + **Vue 3 / Inertia 3**, **MariaDB 12.3**, **Tailwind CSS 4**, dan **Docker**). Proyek ini dirancang sebagai sistem siap produksi (*production-grade portfolio*) yang menekankan integritas data moneter dan persediaan, arsitektur perangkat lunak yang ketat, keamanan berlapis (*defense-in-depth*), performa terukur, serta rangkaian verifikasi otomatis tanpa kompromi.

---

## 1. Ringkasan Eksekutif & Karakteristik Utama

Sistem ini mengelola siklus operasional pengadaan (*procurement*), manajemen persediaan multi-gudang (*multi-warehouse inventory*), katalog master data komprehensif, serta dashboard ringkasan operasional dengan kepatuhan tinggi terhadap prinsip rekayasa perangkat lunak:

- **Nol Aritmetika Floating-Point**: Seluruh kalkulasi uang, kuantitas stok, diskon, dan tarif pajak menggunakan kelas pembungkus `App\Support\Decimal` berbasis ekstensi PHP `bcmath` dengan representasi `string`. Dilarang keras menggunakan `float` atau `double` di seluruh lapisan backend dan frontend guna mencegah cacat presisi moneter IEEE-754.
- **Buku Besar Stok Append-Only & Immutability**: Saldo persediaan instan dicatat pada snapshot `stock_balances`, sedangkan riwayat mutasi dicatat pada ledger `stock_movements` yang bersifat **append-only**. Integritas data mutasi dilindungi di tingkat database MariaDB menggunakan trigger `BEFORE UPDATE` dan `BEFORE DELETE` yang langsung melempar error SQLSTATE `45000` bila terjadi upaya manipulasi atau penghapusan data ledger.
- **State Machine Dokumen Siklus Hidup Ketat**: Dokumen Pesanan Pembelian (PO), Penerimaan Barang (GR), dan Penyesuaian Stok (SA) dikontrol oleh `DocumentStateMachine`. Transisi status yang tidak valid atau perubahan pada dokumen berstatus final otomatis ditolak dengan status HTTP 409 Conflict.
- **Penomoran Dokumen Atomik Bebas Celah (*Gapless Numbering*)**: Penomoran urut dokumen transaksi per periode bulanan (`{PREFIX}/{YYYYMM}/{NNNNN}`) diproses di dalam transaksi database menggunakan penguncian baris pesimistik (`lockForUpdate()`) pada tabel `document_sequences`.
- **Pemisahan Peran Basis Data Berprinsip *Least Privilege***: Koneksi runtime aplikasi (`erp_app`) dibatasi secara ketat hanya pada hak akses DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Seluruh operasi DDL dan pemeliharaan trigger diisolasi pada akun migrasi khusus (`erp_migrator`), dan kredensial migrator tidak pernah diekspos ke environment container PHP atau berkas konfigurasi `src/.env`.
- **Jejak Audit Fail-Closed**: Setiap mutasi model dicatat ke tabel `audit_logs` melalui trait `RecordsAudit`. Jika pencatatan jejak audit gagal karena alasan teknis apa pun, seluruh transaksi database otomatis dibatalkan (*rollback* secara fail-closed).
- **Aksesibilitas Teruji (WCAG 2.1 AA)**: Antarmuka dibangun di atas Design System internal tanpa CDN pihak ketiga dan diaudit lolos otomatis menggunakan `axe-core` dengan 0 temuan berstatus *critical* atau *serious*.

---

## 2. Cakupan Fitur per Modul

<!-- screenshots: ditambahkan pemilik di docs/screenshots/ -->

### A. Dashboard Ringkasan Operasional (`/dashboard`)
- **Metrik Utama Berbasis Izin**: Ringkasan real-time 4 area operasional: Pesanan Pembelian Menunggu Persetujuan, Penerimaan Barang Hari Ini, Nilai Estimasi Persediaan & Jumlah SKU Kritis (di bawah minimum stok), serta Penyesuaian Stok Menunggu Posting.
- **Tabel Transaksi Terkini**: Daftar PO terbuka terbaru dan mutasi stok terakhir dengan penanda gudang dan status dokumen.
- **Performa Terkendali**: Seluruh kalkulasi agregat dioptimalkan dalam batas query konstan ($O(1)$) tanpa perulangan N+1.

### B. Master Data (8 Entitas Inti)
- **Satuan Ukuran (`/master/units`)**: Satuan dasar kuantitas (PCS, BOX, KG, LTR, MTR) dengan validasi unik.
- **Kategori Produk (`/master/product-categories`)**: Struktur kategori pohon hierarkis dengan validasi anti-siklus (*cycle prevention*), pencegahan self-parenting, dan pembatasan kedalaman maksimal $\le 5$ tingkat.
- **Gudang (`/master/warehouses`)**: Pengelolaan lokasi penyimpanan fisik stok barang dengan dukungan status aktif/nonaktif.
- **Tarif Pajak (`/master/tax-rates`)**: Tarif persentase perpajakan (PPN 0%–100%) dengan validasi batas desimal.
- **Syarat Pembayaran (`/master/payment-terms`)**: Termin jatuh tempo pembayaran komersial dalam jumlah hari (CASH, NET 7, NET 14, NET 30).
- **Pemasok (`/master/suppliers`)**: Katalog rekanan vendor/supplier pengadaan dengan nomor kontak dan alamat.
- **Pelanggan (`/master/customers`)**: Katalog customer distribusi dengan informasi plafon kredit dan termin pembayaran.
- **Produk (`/master/products`)**: Master katalog SKU barang dengan harga beli standar, harga jual, stok minimum, flag pelacakan persediaan (`track_stock`), dan relasi ke satuan dasar serta kategori.
- **Perlindungan Integritas Referensi (`ReferenceGuard`)**: Operasi hapus master data secara otomatis memvalidasi relasi ke tabel downstream (PO, GR, Saldo Stok, Mutasi Stok, Faktur) dan mengembalikan respons HTTP 409 Conflict informatif dalam Bahasa Indonesia bila data masih digunakan.

### C. Pembelian & Pengadaan (Purchasing)
- **Pesanan Pembelian / PO (`/purchasing/purchase-orders`)**:
  - Siklus dokumen: `draft` $\rightarrow$ `submitted` $\rightarrow$ `approved` $\rightarrow$ `partial` / `closed` / `cancelled`.
  - Kalkulator moneter P3: perhitungan subtotal bruto (*gross*), diskon baris, dasar pengenaan pajak (*net*), nilai PPN (*tax*), dan grand total (*total*) dengan akurasi 2 desimal berbasis `Decimal`.
  - Dukungan approval workflow dan pembatalan dengan validasi ketergantungan dokumen penerimaan barang.
- **Penerimaan Barang / GR (`/purchasing/goods-receipts`)**:
  - Siklus dokumen: `draft` $\rightarrow$ `posted` $\rightarrow$ `cancelled`.
  - Dibuat berdasarkan referensi Pesanan Pembelian yang telah disetujui (`approved` atau `partial`).
  - Pembaruan kuantitas penerimaan terhadap PO secara atomik dan akurat; transisi status PO otomatis menjadi `partial` atau `closed` saat seluruh item diterima.
  - Alokasi stok masuk instan ke gudang tujuan dan pembaruan biaya rata-rata bergerak persediaan.
  - Pembatalan GR (`cancelled`) menghasilkan mutasi pengurang persediaan otomatis dan mengembalikan kuantitas diterima pada dokumen PO terkait.

### D. Persediaan & Gudang (Inventory)
- **Saldo Stok Realtime (`/inventory/stock-balances`)**: Snapshot kuantitas persediaan saat ini per produk per gudang, dilengkapi informasi harga pokok rata-rata persediaan (*moving average cost*) dan nilai total persediaan.
- **Buku Besar Mutasi Stok (`/inventory/stock-movements`)**: Riwayat lengkap seluruh pergerakan persediaan masuk/keluar (*receipt*, *adjustment_in*, *adjustment_out*, *reversal*) bersifat append-only dengan nomor referensi dokumen dan user pencatat.
- **Kartu Stok Kronologis (`/inventory/stock-card`)**: Penampil riwayat mutasi persediaan per SKU dan per gudang dengan saldo berjalan (*running balance*).
- **Penyesuaian Stok / Opname (`/inventory/stock-adjustments`)**:
  - Siklus dokumen: `draft` $\rightarrow$ `posted` $\rightarrow$ `cancelled`.
  - Mendukung penyesuaian kuantitas fisik (`adjustment_in` untuk kelebihan fisik, `adjustment_out` untuk kehilangan/kerusakan fisik).
  - Mutasi pembalik otomatis saat dokumen penyesuaian dibatalkan.

### E. Penjualan & Distribusi (Sales)
- **Pesanan Penjualan / SO (`/sales/sales-orders`)**:
  - Siklus dokumen: `draft` $\rightarrow$ `approved` $\rightarrow$ `partially_delivered` / `delivered` / `closed` / `void`.
  - Kalkulator moneter: perhitungan subtotal bruto (*gross*), diskon baris hingga 100%, dasar pengenaan pajak (*net*), nilai PPN (*tax*), dan total akhir dengan akurasi 2 desimal berbasis `LineTotalsCalculator`.
  - Alur persetujuan (*approval*) dan pembatalan (*void*) dengan validasi dependensi surat jalan aktif.
- **Surat Jalan / DO (`/sales/delivery-orders`)**:
  - Siklus dokumen: `draft` $\rightarrow$ `posted` $\rightarrow$ `void`.
  - Diterbitkan dari Pesanan Penjualan yang telah disetujui (`approved` atau `partially_delivered`).
  - Posting pengiriman memotong kuantitas fisik gudang via `StockService::decrease`, mencatat snapshot biaya satuan rata-rata bergerak di `delivery_order_items.unit_cost`, dan memperbarui status pesanan penjualan secara atomik.
  - Pembatalan pengiriman terposting (`void`) memulihkan saldo fisik gudang via `StockService::increase` menggunakan snapshot biaya satuan pengiriman awal dan menghitung mundur kemajuan status pesanan penjualan secara akurat.

---

## 3. Arsitektur Monolitik & Standar Teknis

Arsitektur aplikasi memisahkan tanggung jawab secara tegas (*Separation of Concerns*):

```text
[Browser / Klien]
       │
       ▼
[Nginx Web Server] ──────► Rate Limiting, CSP Nonce, HSTS, Gzip Asset Caching
       │
       ▼
[Laravel HTTP Kernel] ───► SecurityHeaders, Auth, Throttling (throttle:erp-write)
       │
       ▼
[FormRequest] ───────────► Sanitasi Input, Validasi DesimalString, Soft-Delete Unique
       │
       ▼
[Service Layer] ─────────► Orkestrasi Transaksi (DB::transaction), ReferenceGuard,
       │                   DocumentStateMachine, DocumentNumberService, StockService
       ▼
[Eloquent Models] ───────► Strict Mode, HasAuditUsers, RecordsAudit (Fail-Closed)
       │
       ▼
[MariaDB Database] ──────► Foreign Key RESTRICT, CHECK Constraints, Append-Only Triggers
```

Dokumentasi arsitektur lebih rinci dapat dipelajari pada:
- [Arsitektur Monolith & Alur Sistem](docs/ARCHITECTURE.md)
- [Arsitektur & Spesifikasi Modul Pembelian](docs/PURCHASING.md)
- [Arsitektur & Spesifikasi Modul Penjualan](docs/SALES.md)
- [Arsitektur & Spesifikasi Modul Inventori](docs/INVENTORY.md)
- [Desain & Konvensi Database 37 Tabel](docs/DATABASE_DESIGN.md)
- [Entity Relationship Diagram (ERD)](docs/ERD.md)
- [Kebijakan Keamanan & Matriks Mitigasi](docs/SECURITY.md)
- [Standar Koding Backend & Konvensi Proyek](docs/CONVENTIONS.md)
- [Konvensi Frontend, UI & Glosarium](docs/FRONTEND_CONVENTIONS.md)
- [Panduan Operasional & Backup](docs/OPERATIONS.md)
- [Laporan Kinerja & Rencana Optimasi Basis Data](docs/PERFORMANCE.md)

---

## 4. Keamanan Berlapis (*Defense-in-Depth*)

1. **Content Security Policy (CSP) & Header Proteksi**:
   - CSP berbasis nonce unik per request: script inline tanpa nonce ditolak peramban.
   - Header keamanan lengkap: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, dan `Strict-Transport-Security`.
2. **Kredensial & Autentikasi Modern**:
   - Algoritma hashing kata sandi `Argon2id` dengan parameter aman memori dan thread.
   - Cookie sesi terisolasi dengan atribut `HttpOnly`, `SameSite=Lax`, dan `Secure`.
3. **Pembersihan Data Sensitif pada Redirect**:
   - Seluruh kegagalan validasi formulir menyaring field kredensial (`password`, `password_confirmation`, `current_password`) sehingga kata sandi tidak pernah disimpan kembali ke dalam flash session store.
4. **Perlindungan Laju Permintaan (Rate Limiting)**:
   - Rute login dilindungi zona rate limiting Nginx dan anti-spoofing `X-Forwarded-For`.
   - Seluruh mutasi state aplikasi (`POST`, `PUT`, `PATCH`, `DELETE`) dilindungi middleware `throttle:erp-write` (kuota 60 request/menit).
   - Antarmuka frontend mengimplementasikan proteksi klik ganda via composable `useSubmitLock` (throttle 1000ms dan status `busy`).
5. **Pencegahan Injeksi SQL**:
   - Query builder memanfaatkan parameter binding penuh.
   - Pencarian teks pada helper `ListQuery` menerapkan sanitasi wildcard SQL `LIKE` (`%`, `_`, `\`) menggunakan escaping backslash (`\`) eksplisit.

---

## 5. Kualitas, Pengujian & CI/CD

Proyek ini menerapkan standar mutu berkelanjutan yang dapat diverifikasi secara mandiri melalui kakas otomatis:

- **Analisis Statis PHP**: Divalidasi menggunakan **PHPStan / Larastan Level 6** pada cakupan kode aplikasi `app/` tanpa error dan tanpa supresi baseline palsu.
- **Standardisasi Gaya Kode**: Diformat secara ketat menggunakan **Laravel Pint** sesuai PSR-12.
- **Suite Pengujian Pest**: Suite pengujian mencakup Unit, Feature, dan Architecture Tests (termasuk verifikasi integritas trigger, pencegahan fungsi berbahaya, dan isolasi layer).
- **Pengujian Frontend Vitest & Aksesibilitas**: Unit test komponen UI, composables, dan audit aksesibilitas otomatis **axe-core** (0 pelanggaran *critical* / *serious*).
- **Skrip Verifikasi Hardening**: Skrip `scripts/verify-hardening.sh` menguji 27 butir hardening infrastruktur, database privileges, dan isolasi rahasia.
- **Pemindaian Rahasia Gitleaks**: Git hooks lokal dan CI workflow memeriksa seluruh commit dari potensi kebocoran token atau kunci rahasia.
- **Audit Dependensi**: Pemindaian kerentanan berkala menggunakan `composer audit` dan `npm audit`.
- **Continuous Integration (GitHub Actions)**: Workflow `.github/workflows/ci.yml` menjalankan seluruh gerbang kualitas secara otomatis pada pull request dan push ke cabang utama.
- **Dependabot**: Konfigurasi `.github/dependabot.yml` memantau pembaruan rutin untuk Composer, NPM, GitHub Actions, dan Docker Compose.

---

## 6. Panduan Menjalankan Sistem (Quickstart)

### Prasyarat
- Sistem Operasi: Linux atau Windows dengan WSL2 (Ubuntu 22.04 / 24.04 disarankan).
- Docker Engine $\ge 24.0$ dan Docker Compose v2.
- Kakas `make`, `git`, dan `openssl`.

### 1. Kloning Repositori & Konfigurasi Git Hooks
```bash
git clone git@github.com:widiartowahyu3/erp-laravel.git erp-trading
cd erp-trading

# Pasang git hooks keamanan lokal (.githooks: Gitleaks, regex secret detector, Conventional Commits)
make hooks
```

### 2. Inisialisasi Berkas Lingkungan & Rahasia
```bash
# 1. Konfigurasi infrastruktur di root repositori
cp .env.example .env
APP_PASS=$(openssl rand -hex 24)
MIGRATOR_PASS=$(openssl rand -hex 24)
sed -i "s/change_this_to_a_secure_random_app_password/${APP_PASS}/" .env
sed -i "s/change_this_to_a_secure_random_migrator_password/${MIGRATOR_PASS}/" .env
chmod 600 .env

# 2. Kata sandi root MariaDB terpisah (Docker Compose Secret)
openssl rand -hex 24 > secrets/db_root_password.txt
chmod 644 secrets/db_root_password.txt
chmod 700 secrets

# 3. Konfigurasi aplikasi Laravel di src/.env
cp src/.env.example src/.env
sed -i "s/APP_ENV=production/APP_ENV=local/" src/.env
sed -i "s/APP_DEBUG=false/APP_DEBUG=true/" src/.env
sed -i "s|APP_URL=https://erp.example.com|APP_URL=http://127.0.0.1:8080|" src/.env
sed -i "s/SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false/" src/.env
sed -i "s/DB_PASSWORD=change_this_to_erp_app_password/DB_PASSWORD=${APP_PASS}/" src/.env
chmod 600 src/.env
```

### 3. Membangun & Menjalankan Stack Docker
```bash
# Bangun image Docker
make build

# Jalankan seluruh service (Nginx, PHP-FPM, MariaDB) di latar belakang
make up

# Generate application key Laravel
make artisan cmd="key:generate"

# Jalankan migrasi skema tabel via koneksi terisolasi erp_migrator
make migrate

# Jalankan seeder referensi baku (roles, permissions, units, payment terms, coa, tax rates)
make seed

# Buat akun Super Administrator pertama secara interaktif
make artisan cmd="erp:admin:create"

# Kompilasi bundel aset frontend
docker compose --profile tools run --rm node npm run build
```

### 4. Mengisi Data Uji Realistis (Opsional / Development)
```bash
# Isi data demo master data (8 entitas katalog produk dan rekanan bisnis)
make seed-demo

# Isi data demo transaksi operasional berantai (PO, GR, Saldo Stok, dan SA via domain services)
make seed-demo-tx
```

### 5. Akses Aplikasi
- **Aplikasi Web**: [http://127.0.0.1:8080](http://127.0.0.1:8080)
- **Halaman Login**: [http://127.0.0.1:8080/login](http://127.0.0.1:8080/login)
- **Katalog Styleguide (Khusus APP_ENV=local)**: [http://127.0.0.1:8080/dev/styleguide](http://127.0.0.1:8080/dev/styleguide)
- **Healthcheck Service**: [http://127.0.0.1:8080/up](http://127.0.0.1:8080/up)

---

## 7. Perintah Pengujian & Operasional

Seluruh perintah perawatan dan pengujian distandarisasi melalui target `Makefile`:

```bash
# Menjalankan seluruh gerbang kualitas (Pint + Larastan + Pest + Vitest + ESLint)
make check

# Menjalankan suite pengujian backend Pest (auto migrasi test-db)
make test

# Menjalankan suite pengujian frontend Vitest & axe-core
make test-js

# Menjalankan analisis statis kode PHP (PHPStan/Larastan level 6)
make analyse

# Menjalankan inspeksi gaya kode PHP (Laravel Pint)
make lint

# Menjalankan linting frontend Vue/JS (ESLint)
make lint-js

# Menjalankan skrip verifikasi keamanan & hardening (24 butir)
make verify

# Melakukan pencadangan basis data terkompresi ke direktori backups/
make backup

# Memvalidasi integritas file cadangan terhadap basis data uji sementara
make restore-check
```

---

## 8. Tata Letak Direktori Utama

```text
.
├── .agent/skills/         # Pedoman dan aturan otomatis agen AI pengembang
├── .github/               # Workflow GitHub Actions CI & konfigurasi Dependabot
├── .githooks/             # Git hooks lokal (commit-msg, pre-commit secret detection)
├── backups/               # Direktori penyimpanan arsip cadangan basis data (.gitignore)
├── docker/                # Konfigurasi container Docker (Nginx, PHP-FPM, MariaDB)
├── docs/                  # Dokumentasi arsitektur, keamanan, database, dan operasional
├── review/                # Berkas log audit dan bukti verifikasi independen
├── scripts/               # Skrip shell otomasi (hardening, backup, restore-check)
├── secrets/               # Secret files Docker (chmod 700, di luar repositori git)
├── src/                   # Kode sumber aplikasi Laravel & frontend Vue
│   ├── app/               # Logika aplikasi (Controllers, Models, Services, Support)
│   ├── bootstrap/         # Bootstrapping framework & exception handling
│   ├── config/            # Konfigurasi aplikasi, keamanan, sesi, dan database
│   ├── database/          # Skema migrasi tabel DDL dan seeder transaksi
│   ├── resources/         # Kode frontend Vue 3, layout, halaman, dan design system
│   ├── routes/            # Definisi rute web dan konsol
│   └── tests/             # Suite pengujian Pest (Arch, Feature, Unit)
├── compose.yaml           # Definisi service multi-container Docker Compose
└── Makefile               # Antarmuka perintah otomasi terpadu
```

---

## 9. Batasan Sistem Saat Ini & Rencana Masa Depan (*Roadmap*)

Secara transparan dan jujur, implementasi saat ini memiliki batasan arsitektural yang disengaja:

1. **Mata Uang Tunggal (Single Currency - IDR)**: Seluruh transaksi beroperasi eksklusif dalam Rupiah Indonesia tanpa konversi valuta asing.
2. **Satuan Dasar Tunggal per Produk**: Setiap produk hanya memiliki satu satuan inventori dasar (`base_unit_id`). Konversi multi-satuan dinamis bertingkat (*UOM conversion*) belum diimplementasikan di tingkat transaksi.
3. **Tanpa Dokumen Mutasi Antar-Gudang Langsung**: Fitur transfer persediaan antar-gudang saat ini ditangani secara manual melalui kombinasi dokumen Penyesuaian Keluar (`adjustment_out`) pada gudang asal dan Penyesuaian Masuk (`adjustment_in`) pada gudang tujuan.
4. **Modul Hilir Belum Tersedia**: Modul Penjualan (Pesanan Penjualan/SO, Surat Jalan/DO, Faktur Penjualan), Piutang Usaha, Hutang Usaha, Pembayaran Kas/Bank, Buku Kas/Bank, Jurnal Akuntansi Umum, dan Laporan Keuangan dialokasikan pada fase pengembangan berikutnya.
5. **Antarmuka Pengguna Administrasi Terbatas**: Manajemen akun pengguna, pengaturan peran RBAC, dan penampil log audit (`audit_logs`) saat ini dikelola melalui database seeder dan perintah Artisan konsol, belum memiliki antarmuka visual (GUI).
6. **Target Multi-Stage Docker `prod`**: Target `prod` pada `docker/php/Dockerfile` belum mengemas kode aplikasi ke dalam image dan belum digunakan untuk alur rilis produksi aktif (alur kerja saat ini menggunakan target `dev` terisolasi).
7. **Status CI GitHub Actions**: Konfigurasi `.github/workflows/ci.yml` telah tervalidasi secara sintaks dan struktur, namun status kelulusan badge CI di repositori jarak jauh bergantung pada proses push yang dijalankan oleh pemilik repositori.

# Arsitektur Monolith & Alur Sistem

Dokumen ini mendokumentasikan arsitektur sistem monolitik ERP Distribusi / Trading yang dibangun menggunakan Laravel 13, Vue 3 (Inertia.js), Tailwind CSS v4, dan MariaDB 12.3 LTS di atas infrastruktur Docker hardened.

---

## 1. Diagram Arsitektur Keseluruhan

```mermaid
flowchart TD
    subgraph Host ["Host Lingkungan (WSL 2 Ubuntu)"]
        Browser["Peramban Klien (Browser)"]
    end

    subgraph Network_Frontend ["Docker Network: frontend (Bridge)"]
        Nginx["erp-trading-web\n(Nginx 1.30.6 Unprivileged :8080)"]
        Node["erp-trading-node\n(Vite Dev Server :5174 -> :5173)"]
        PHP["erp-trading-php\n(PHP 8.5.12 FPM :9000)"]
    end

    subgraph Network_Backend ["Docker Network: backend (Internal Bridge)"]
        DB["erp-trading-db\n(MariaDB 12.3.3 LTS :3306)"]
    end

    Browser -- "HTTP / HTTPS (:8080)" --> Nginx
    Browser -. "WebSocket & Asset HMR (:5174)" .-> Node
    Nginx -- "FastCGI Pass (:9000)" --> PHP
    Nginx -- "Static Assets (RO)" --> StaticAssets["./src/public"]
    PHP -- "DML Queries (erp_app)" --> DB
    PHP -. "DDL Migrations (erp_migrator)" .-> DB
```

---

## 2. Pemisahan Hak Akses Basis Data (Database Privilege Separation)

Untuk mencegah eksploitasi SQL Injection yang berujung pada perubahan skema tabel atau penghancuran data, hak akses MariaDB dipisahkan secara fisik ke dalam dua kredensial pengguna yang berbeda:

```mermaid
flowchart LR
    subgraph PHP_Container ["Container PHP (Runtime)"]
        AppOps["Operasional Aplikasi Sehari-hari\n(Connection: 'mariadb')"]
        MigrateOps["Perintah Artisan Migrasi\n(Connection: 'mariadb_migrator')"]
    end

    subgraph MariaDB ["MariaDB Server (erp-trading-db)"]
        User_App["User: erp_app\nHak: SELECT, INSERT, UPDATE, DELETE\n(Strictly DML Only)"]
        User_Migrator["User: erp_migrator\nHak: CREATE, ALTER, DROP, INDEX, DML\n(DDL + Migrations Only)"]
        User_Root["User: root\nHak: ALL PRIVILEGES\n(Host: localhost only, Docker Secret)"]
    end

    AppOps --> User_App
    MigrateOps --> User_Migrator
```

- **`erp_app`**: Digunakan oleh seluruh request web dan API sehari-hari. Jika terjadi celah SQL injection, perintah DDL seperti `DROP TABLE` atau `CREATE TABLE` akan ditolak langsung oleh MariaDB engine (`SQLSTATE[42000] Access/Command denied`).
- **`erp_migrator`**: Kredensial ini **tidak disimpan** di dalam `src/.env` aplikasi. Kredensial diinjeksikan secara temporer dari `.env` root repositori hanya saat menjalankan perintah `make migrate`.
- **`root`**: Terikat secara eksklusif ke host lokal database (`localhost`), kata sandi di-generate secara acak berkekuatan tinggi dan dipasang melalui Docker Compose secret (`secrets/db_root_password.txt`).

---

## 3. Alur Siklus Hidup Request Inertia.js

Inertia.js memungkinkan arsitektur monolitik klasik Laravel menyajikan frontend reaktif Vue 3 tanpa kompleksitas API terpisah:

```mermaid
sequenceDiagram
    autonumber
    actor User as Pengguna (Browser)
    participant Nginx as Nginx Web (:8080)
    participant Middleware as SecurityHeaders Middleware
    participant Controller as Laravel Route / Controller
    participant Inertia as HandleInertiaRequests Middleware
    participant Vue as Vue 3 Client (AppLayout)

    Note over User, Vue: Skenario A: Kunjungan Halaman Pertama (Initial Full Page Load)
    User->>Nginx: GET /
    Nginx->>Middleware: Forward FastCGI
    Middleware->>Middleware: Generate per-request nonce (Vite::useCspNonce)
    Middleware->>Controller: Lanjutkan request
    Controller->>Inertia: Inertia::render('Dashboard')
    Inertia->>Middleware: Return Blade view app.blade.php + data-page JSON
    Middleware->>Nginx: Tambahkan Security Headers (CSP nonce, X-Frame, dll)
    Nginx->>User: HTML Dokumen Lengkap (Status 200)
    User->>Vue: Vue me-mount #app dan membaca data-page

    Note over User, Vue: Skenario B: Navigasi Klien (Inertia SPA Visit)
    User->>Vue: Klik menu navigasi (Inertia Link)
    Vue->>Nginx: GET /halaman-lain (Header: X-Inertia: true)
    Nginx->>Middleware: Forward FastCGI
    Middleware->>Controller: Lanjutkan request
    Controller->>Inertia: Inertia::render('PageName', props)
    Inertia->>Middleware: Return Pure JSON Response
    Middleware->>Nginx: Tambahkan Security Headers
    Nginx->>Vue: Respons JSON (X-Inertia: true)
    Vue->>User: Update Komponen Vue di tempat tanpa reload halaman
```

---

## 4. Isolasi Sistem Berkas & Keamanan Kontainer

- **Prinsip Least Privilege**: Semua kontainer berjalan sebagai user unprivileged (`UID 101` untuk Nginx, `UID 1000` untuk PHP dan Node, `mysql 999` untuk MariaDB).
- **Read-Only Root Filesystem**: Semua kontainer berjalan dengan `read_only: true`. Operasi tulis hanya diizinkan pada partisi temporer `tmpfs` berukuran terbatas (`/tmp`, `/var/run`) dan cache direktori spesifik.
- **Isolasi Berkas Sensitif**: Container PHP hanya me-mount direktori `./src:/var/www/html`. Berkas konfigurasi Docker, `.git`, direktori `secrets/`, dan `.env` infrastruktur tidak dapat diakses sama sekali dari dalam runtime aplikasi.

---

## 5. Lapisan Aplikasi

```mermaid
flowchart TD
    Route["Route: auth, can:izin, throttle"] --> Controller["Controller tipis"]
    Controller --> Request["FormRequest: validasi input"]
    Controller --> Service["Service: DB::transaction, aturan bisnis"]
    Service --> Model["Model: audit, tanpa logika alur"]
    Service --> Journal["JournalService"]
    Service --> Stock["StockService"]
    Service --> Number["DocumentNumberService"]
    Model --> DB[("MariaDB: FK, CHECK, trigger")]
    Journal --> DB
    Stock --> DB
    Number --> DB
    Reports["Reports: kueri baca-saja"] --> DB
    Controller --> Reports
```

Aturan bisnis hanya ada di service. Laporan tidak melewati service tulis dan tidak punya efek samping.

## 6. Alur Dokumen

```mermaid
flowchart LR
    PO[Pesanan Pembelian] --> GR[Penerimaan Barang]
    GR --> PI[Faktur Pembelian]
    PI --> PayOut[Pembayaran Keluar]
    SO[Pesanan Penjualan] --> DO[Surat Jalan]
    DO --> SI[Faktur Penjualan]
    SI --> PayIn[Pembayaran Masuk]
```

Penerimaan, pengiriman, dan penagihan boleh bertahap. Satu faktur mengacu ke tepat satu SO atau PO.

## 7. Alur Jurnal Otomatis

```mermaid
flowchart TD
    GR2[Posting GR] -->|"D 1301 / K 2103"| J[(Jurnal posted)]
    PI2[Posting faktur pembelian] -->|"D 2103, 1401 / K 2101"| J
    Out[Pembayaran keluar] -->|"D 2101 / K kas atau bank"| J
    DO2[Posting DO] -->|"D 5101 / K 1301"| J
    SI2[Posting faktur penjualan] -->|"D 1201, 4201 / K 4101, 2201"| J
    In[Pembayaran masuk] -->|"D kas atau bank / K 1201"| J
    SA[Posting penyesuaian stok] -->|"1301 dan 5201"| J
    Void[Pembatalan dokumen] -->|"Jurnal pembalik"| J
    J --> R[Laporan keuangan]
```

Peta akun dan rumus lengkap ada di [`ACCOUNTING.md`](ACCOUNTING.md).

## 8. Model Data Ringkas

```mermaid
erDiagram
    SUPPLIER ||--o{ PURCHASE_ORDER : menerima
    PURCHASE_ORDER ||--o{ GOODS_RECEIPT : dipenuhi
    PURCHASE_ORDER ||--o{ PURCHASE_INVOICE : ditagih
    CUSTOMER ||--o{ SALES_ORDER : memesan
    SALES_ORDER ||--o{ DELIVERY_ORDER : dipenuhi
    SALES_ORDER ||--o{ SALES_INVOICE : ditagih
    PAYMENT ||--o{ PAYMENT_ALLOCATION : dialokasikan
    SALES_INVOICE ||--o{ PAYMENT_ALLOCATION : dilunasi
    PURCHASE_INVOICE ||--o{ PAYMENT_ALLOCATION : dilunasi
    PRODUCT ||--o{ STOCK_BALANCE : tersimpan
    WAREHOUSE ||--o{ STOCK_BALANCE : menyimpan
    JOURNAL ||--|{ JOURNAL_LINE : berisi
    CHART_OF_ACCOUNT ||--o{ JOURNAL_LINE : dipakai
```

Skema lengkap ada di [`ERD.md`](ERD.md) dan [`DATABASE_DESIGN.md`](DATABASE_DESIGN.md).

## 9. Keputusan Desain Utama

- **Desimal dengan bcmath**: `App\Support\Decimal` menghitung uang dan kuantitas sebagai string. `float` membuat selisih pembulatan yang tidak bisa diaudit.
- **State machine dokumen**: `DocumentStateMachine` menolak transisi status yang tidak sah. Riwayat mutasi stok bersifat append-only dan dijaga trigger di basis data.
- **Urutan penguncian tetap**: semua service mengunci baris dalam urutan yang sama untuk mencegah deadlock. Urutannya ada di [`ACCOUNTING.md`](ACCOUNTING.md).
- **Akun barang diterima belum ditagih (2103)**: penerimaan barang mencatat persediaan sebelum faktur pemasok datang, lalu faktur menutup akun ini. Saldo 2103 berarti ada barang yang belum ditagih.
- **Audit fail-closed**: kegagalan mencatat audit membatalkan transaksinya. Tidak ada mutasi tanpa jejak.
- **Laporan baca-saja**: laporan dihitung langsung dari jurnal dengan agregat SQL, jadi tidak ada ringkasan tersimpan yang bisa berbeda dari sumbernya.

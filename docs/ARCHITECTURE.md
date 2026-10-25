# Arsitektur Monolith & Alur Sistem (Fase 1)

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

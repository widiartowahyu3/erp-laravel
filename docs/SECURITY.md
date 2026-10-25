# Kebijakan & Standar Hardening Keamanan (Fase 0)

Dokumen ini mendokumentasikan seluruh lapisan pengamanan (defense-in-depth) yang diterapkan pada Fase 0 beserta alasan teknis dan checklist review arsitek/pengawas.

---

## 1. Daftar Hardening & Alasan Penerapan

### A. Lapisan Host & Git Repository
1. **Pemisahan Kredensial & Deteksi Kebocoran Otomatis**:
   - Berkas `.env` dan segala variasinya diabaikan secara ketat oleh `.gitignore` (kecuali `.env.example`).
   - Hook Git `pre-commit` memeriksa staged diff terhadap pola rahasia (`APP_KEY=base64:`, hardcoded password, private key).
   - Hook `pre-commit` menjalankan pemindaian menyeluruh via Docker image resmi `zricethezav/gitleaks:v8.30.1`.
2. **Standardisasi Pesan & Integritas Commit**:
   - Hook `commit-msg` mewajibkan Conventional Commits agar setiap riwayat perubahan dapat dilacak secara terstruktur.
   - Hak akses kunci privat SSH dipertahankan di lingkungan pemilik tanpa dicetak ke log.

### B. Lapisan Container (Docker Engine & Compose)
1. **User Non-Root pada Runtime**:
   - `web` berjalan sebagai user unprivileged `101:101`.
   - `php` berjalan sebagai user `appuser` (UID/GID 1000 sesuai user WSL host).
   - `node` berjalan sebagai user `1000:1000`.
   - `db` (MariaDB) menjalankan proses daemon `mariadbd` sebagai user khusus `mysql` (UID 999).
   - *Alasan*: Mencegah eskalasi hak akses host jika terjadi eksploitasi container breakout (RCE).
2. **Root Filesystem Read-Only (`read_only: true`)**:
   - Seluruh service utama berjalan dengan root filesystem terkunci (read-only).
   - Hanya direktori sementara (`/tmp`, `/var/run`, `/run/mysqld`) yang dialokasikan via memory `tmpfs`.
   - *Alasan*: Menghalangi penyerang mengunggah malware, webshell, atau memodifikasi binary sistem saat runtime.
3. **Reduksi Linux Capabilities & Anti-Privilege Escalation**:
   - Seluruh container menerapkan `security_opt: [no-new-privileges:true]`.
   - Seluruh container menerapkan `cap_drop: [ALL]`.
   - Service `db` hanya menambahkan kapabilitas minimum absolut yang diwajibkan oleh entrypoint resmi: `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE` (untuk inisialisasi volume data oleh gosu).
   - *Alasan*: Menutup vektor eksploitasi berbasis panggilan kernel Linux (kernel exploits).
4. **Pembatasan Port & Segmentasi Jaringan**:
   - Port `web` hanya diexpose ke antarmuka loopback lokal: `127.0.0.1:8080`.
   - Port `db` (MariaDB) **TIDAK** dipublikasikan ke host sama sekali. Akses database dari host hanya melalui `docker compose exec`.
   - Jaringan disegmentasi menjadi:
     - `frontend`: Menghubungkan `web` dan `php`.
     - `backend` (`internal: true`): Menghubungkan `php` dan `db`. Service database tidak memiliki rute keluar internet dan tidak dapat diakses langsung oleh web server.

### C. Lapisan Web Server (Nginx)
1. **Pencegahan Kebocoran Informasi (Information Disclosure)**:
   - `server_tokens off;` menyembunyikan versi Nginx.
   - `fastcgi_hide_header X-Powered-By;` menyembunyikan versi PHP.
2. **Pembatasan Eksekusi PHP**:
   - Nginx hanya meneruskan request ke FastCGI PHP untuk path tepat `/index.php`.
   - Seluruh request file `.php` lainnya langsung diputus dengan respon HTTP 404.
   - Direktori statis seperti `/storage`, `/uploads`, `/vendor`, `/node_modules`, `.git`, `.env` diblokir permanen.
3. **Proteksi DoS Dasar**:
   - Mengaktifkan `limit_req_zone` untuk rate limiting IP.
   - Membatasi `client_max_body_size 32M` dan timeout ketat (`keepalive_timeout 30s`, `client_body_timeout 15s`).

### D. Lapisan PHP Runtime (FPM vs CLI)
1. **Pemisahan Kebijakan FPM dan CLI**:
   - Pool PHP-FPM (`php-fpm-hardening.conf`) menonaktifkan fungsi-fungsi sistem berbahaya via `php_admin_value[disable_functions]`:
     `exec, passthru, shell_exec, system, proc_open, popen, pcntl_exec, dl, symlink, link, mail, show_source, posix_kill, posix_mkfifo, posix_setuid, posix_setgid`.
   - `php_admin_flag[allow_url_fopen] = off` memblokir SSRF / remote inclusion.
   - `open_basedir = /var/www/html:/tmp` mengurung eksekusi file PHP di lingkup proyek.
   - CLI (`php-cli.ini`) tetap mempertahankan fungsi `proc_open` agar Composer dan Artisan dapat berjalan lancar.
2. **Ketiadaan Ekstensi `pcntl`**:
   - Ekstensi `pcntl` tidak dikompilasi pada runtime PHP web untuk mencegah forking proses berbahaya.

### E. Lapisan Database (MariaDB 12.3 LTS)
1. **Pemisahan Hak Akses Aplikasi (Principle of Least Privilege)**:
   - Dibuat 2 pengguna database terpisah:
     - `erp_app`: Khusus request web aplikasi. Dibatasi **HANYA** DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Dilarang melakukan DDL (`CREATE`, `ALTER`, `DROP`).
     - `erp_migrator`: Khusus migrasi skema database. Memiliki izin DDL dan DML migrasi, tetapi **TIDAK** memiliki izin `FILE`, `SUPER`, `PROCESS`, atau `GRANT OPTION`.
   - **Kebijakan Injeksi Kredensial**: Berkas `src/.env` milik Laravel hanya boleh berisi kredensial `erp_app`. Kredensial `erp_migrator` HANYA disuntikkan saat menjalankan migrasi (environment sekali jalan), tidak pernah disimpan di `src/.env` maupun environment container `php` yang berjalan.
2. **Pengelolaan Rahasia Root & Pembatasan Host**:
   - Kata sandi root MariaDB dikelola melalui berkas `secrets/db_root_password.txt` (permission 600, di-.gitignore) dan dipasang via Docker Compose `secrets` (`MARIADB_ROOT_PASSWORD_FILE`). Kata sandi root tidak dicantumkan di file `.env` infra maupun environment container mana pun.
   - `MARIADB_ROOT_HOST=localhost` memastikan user `root` tidak dapat melakukan login melalui jaringan TCP/IP.
3. **Parameter Pengerasan MariaDB**:
   - `local_infile = 0` memblokir pembacaan file lokal server melalui query SQL.
   - `symbolic-links = 0` menolak symlink database tables.
   - Menghapus seluruh user anonim saat inisialisasi.
   - Healthcheck menggunakan skrip internal `/usr/local/bin/healthcheck.sh --connect --innodb_initialized` tanpa mengekspos kata sandi pada command line arguments.

---

## 2. Checklist Review Arsitek / Pengawas

- [x] Repositori bersih dari file `.env`, file `.key`, dan private key (`git ls-files`).
- [x] Hook `pre-commit` (Gitleaks) dan `commit-msg` (Conventional Commits) aktif dan executable.
- [x] Multi-stage Dockerfile PHP menggunakan rilis stabil resmi `php:8.4.27-fpm-alpine`.
- [x] Ekstensi `pcntl` tidak terpasang; ekstensi wajib (`pdo_mysql`, `intl`, `bcmath`, `opcache`, `zip`) terverifikasi aktif.
- [x] `disable_functions` aktif pada request web (FPM), sedangkan CLI tetap mengizinkan `proc_open`.
- [x] Container web, php, dan node berjalan 100% sebagai non-root.
- [x] MariaDB daemon berjalan sebagai user `mysql`.
- [x] `read_only: true`, `security_opt: [no-new-privileges:true]`, dan `cap_drop: [ALL]` aktif di seluruh container.
- [x] Database tidak terhubung ke jaringan `frontend` dan port database tidak diexpose ke host.
- [x] User `erp_app` terbukti gagal saat menjalankan DDL (`CREATE TABLE`), namun sukses melakukan DML.
- [x] Seluruh 38 pengujian pada `make verify` lulus (100% PASS).
- [x] Berkas probe sementara (`public/index.php`, `public/other.php`) telah dihapus dan tidak tertinggal di Git.
- [x] Tidak ada framework Laravel/Vue, tabel aplikasi, atau kode bisnis yang terpasang pada Fase 0.

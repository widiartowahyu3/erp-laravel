# Kebijakan & Standar Hardening Keamanan (Fase 0 - Revisi)

Dokumen ini mendokumentasikan seluruh lapisan pengamanan (defense-in-depth) yang diterapkan pada Fase 0 beserta alasan teknis dan checklist review arsitek/pengawas.

---

## 1. Daftar Hardening & Alasan Penerapan

### A. Lapisan Host & Git Repository
1. **Pemisahan Kredensial & Deteksi Kebocoran Otomatis**:
   - Berkas `.env` infra dan segala variasinya diabaikan secara ketat oleh `.gitignore` (kecuali `.env.example`).
   - Direktori `secrets/*` diabaikan sepenuhnya oleh `.gitignore` (hanya `secrets/README.md` yang terlacak).
   - Hook Git `pre-commit` memeriksa staged diff terhadap pola rahasia (`APP_KEY=base64:`, live password/token/secret tanpa placeholder, berkas dalam `secrets/`, dan private key).
   - Hook `pre-commit` menjalankan pemindaian menyeluruh via Docker image resmi terpin `zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f`.
2. **Standardisasi Pesan & Integritas Commit**:
   - Hook `commit-msg` mewajibkan Conventional Commits agar setiap riwayat perubahan dapat dilacak secara terstruktur.
   - Hak akses kunci privat SSH dipertahankan di lingkungan pemilik tanpa dicetak ke log.
   - Kakas `make hooks` disediakan untuk kemudahan inisialisasi hook Git lokal.

### B. Lapisan Container (Docker Engine & Compose)
1. **Isolasi Struktur Kode Aplikasi (`src/`)**:
   - Kode aplikasi dibatasi hanya hidup di dalam direktori `src/`.
   - Service `php`: bind mount **HANYA** `./src:/var/www/html`.
   - Service `web`: bind mount **HANYA** `./src/public:/var/www/html/public:ro`.
   - Service `node`: bind mount **HANYA** `./src:/var/www/html`.
   - *Alasan*: Container aplikasi tidak memiliki akses mount ke root repositori (`.`), `.git`, `.env` infra, konfigurasi Docker (`docker/`), maupun direktori `secrets/`.
2. **User Non-Root pada Runtime**:
   - `web` berjalan sebagai user unprivileged `101:101`.
   - `php` berjalan sebagai user `appuser` (UID/GID 1000 sesuai user WSL host).
   - `node` berjalan sebagai user `${UID:-1000}:${GID:-1000}`.
   - `db` (MariaDB) menjalankan proses daemon `mariadbd` sebagai user khusus `mysql` (UID 999).
   - *Alasan*: Mencegah eskalasi hak akses host jika terjadi eksploitasi container breakout (RCE).
3. **Root Filesystem Read-Only (`read_only: true`) & Tmpfs Ketat**:
   - Seluruh service berjalan dengan root filesystem terkunci (read-only).
   - Direktori sementara dialokasikan via memory `tmpfs` dengan mode hak akses paling ketat yang berfungsi dan pembatasan kapasitas (`size=`):
     - `web`: `/tmp` (1777, 64m), `/var/cache/nginx` (700, UID 101, 64m), `/var/run` (700, UID 101, 16m).
     - `php`: `/tmp` (1777, 256m), `/var/run` (700, UID 1000, 16m).
     - `db`: `/run/mysqld` (755, UID 999, 16m), `/tmp` (1777, 256m), `/var/log/mysql` (755, UID 999, 64m).
     - `node`: `/tmp` (1777, 128m).
   - Direktori cache paket (`composer_cache` di `/tmp/composer` dan `npm_cache` di `/home/node/.npm`) dipasang sebagai named volume dengan permission non-root yang terbukti dapat ditulis (*writable*).
   - *Alasan*: Menghalangi penyerang mengunggah malware, webshell, atau memodifikasi binary sistem saat runtime.
4. **Reduksi Linux Capabilities & Anti-Privilege Escalation**:
   - Seluruh container menerapkan `security_opt: [no-new-privileges:true]`.
   - Seluruh container menerapkan `cap_drop: [ALL]`.
   - Service `db` hanya menambahkan kapabilitas minimum absolut yang diwajibkan oleh entrypoint resmi: `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE`.
5. **Pembatasan Port & Segmentasi Jaringan**:
   - Port `web` hanya diexpose ke antarmuka loopback lokal: `127.0.0.1:8080`.
   - Port `node` (Vite) hanya diexpose ke loopback lokal: `127.0.0.1:5173`.
   - Port `db` (MariaDB) **TIDAK** dipublikasikan ke host sama sekali. Akses database dari host hanya melalui `docker compose exec`.
   - Jaringan disegmentasi menjadi:
     - `frontend`: Menghubungkan `web`, `php`, dan `node`.
     - `backend` (`internal: true`): Menghubungkan `php` dan `db`. Database tidak memiliki akses keluar internet.

### C. Lapisan Web Server (Nginx)
1. **Pencegahan Kebocoran Informasi (Information Disclosure)**:
   - `server_tokens off;` menyembunyikan versi Nginx.
   - `fastcgi_hide_header X-Powered-By;` menyembunyikan versi PHP.
2. **Pembatasan Eksekusi PHP**:
   - Nginx hanya meneruskan request ke FastCGI PHP untuk path tepat `/index.php`.
   - Seluruh request file `.php` lainnya langsung diputus dengan respon HTTP 404.
3. **Pemblokiran Direktori & Berkas Sensitif Berbasis Batas Segmen Path (Anchored)**:
   - Aturan segmen path: `location ~* ^/(?:\.env|\.git|vendor|node_modules|storage|uploads)(?:/|$) { return 404; }`
   - Aturan berkas konfigurasi: `location ~* ^/(?:composer\.(?:json|lock)|package(?:-lock)?\.json|artisan)$ { return 404; }`
   - Memastikan tidak ada false positive terhadap berkas aset sah seperti `/build/assets/vendor-test.js` atau `/ok.txt`.
4. **Proteksi DoS Dasar**:
   - Mengaktifkan `limit_req_zone` untuk rate limiting IP.
   - Membatasi `client_max_body_size 32M` dan timeout ketat.

### D. Lapisan PHP Runtime (FPM vs CLI)
1. **Pemisahan Kebijakan FPM dan CLI**:
   - Pool PHP-FPM (`php-fpm-hardening.conf`) menonaktifkan fungsi-fungsi sistem berbahaya via `php_admin_value[disable_functions]`:
     `exec, passthru, shell_exec, system, proc_open, popen, pcntl_exec, dl, symlink, link, mail, show_source, posix_kill, posix_mkfifo, posix_setuid, posix_setgid`.
   - `php_admin_flag[allow_url_fopen] = off` memblokir SSRF / remote inclusion.
   - `open_basedir = /var/www/html:/tmp` mengurung eksekusi file PHP di lingkup proyek.
   - CLI (`php-cli.ini`) tetap mempertahankan fungsi `proc_open` agar Composer dan Artisan dapat berjalan lancar.
2. **Ketiadaan Ekstensi `pcntl` & Verifikasi Ekstensi**:
   - Ekstensi `pcntl` tidak dikompilasi pada runtime PHP (baik FPM maupun CLI) untuk mencegah forking proses berbahaya.
   - Ekstensi wajib (`pdo_mysql`, `intl`, `bcmath`, `zip`, `Zend OPcache`) terverifikasi aktif pada runtime FPM dan CLI.
3. **Probe Independen FPM via `cgi-fcgi`**:
   - Kakas `fcgi` (`cgi-fcgi`) dipasang pada stage dev PHP untuk memanggil pool FastCGI 127.0.0.1:9000 secara independen via berkas probe sementara di `/tmp` (non-destruktif) tanpa bergantung pada keberadaan berkas web root publik.
   - Memverifikasi fungsi kritis FPM: `disable_functions` menonaktifkan fungsi berbahaya, `exec_exists=false`, `proc_open_exists=false`, `open_basedir=/var/www/html:/tmp`, `allow_url_fopen=0`, dan `expose_php=""`.

### E. Lapisan Database (MariaDB 12.3 LTS)
1. **Pemisahan Hak Akses Aplikasi (Principle of Least Privilege)**:
   - Dibuat 2 pengguna database terpisah:
     - `erp_app`: Khusus request web aplikasi. Dibatasi **HANYA** DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Dilarang melakukan DDL (`CREATE`, `ALTER`, `DROP`).
     - `erp_migrator`: Khusus migrasi skema database. Memiliki izin DDL dan DML migrasi, tetapi **TIDAK** memiliki izin `FILE`, `SUPER`, `PROCESS`, atau `GRANT OPTION`.
   - **Kebijakan Injeksi Kredensial**: Berkas `src/.env` milik Laravel hanya boleh berisi kredensial `erp_app`. Kredensial `erp_migrator` HANYA disuntikkan saat menjalankan migrasi (environment sekali jalan), tidak pernah disimpan di `src/.env` maupun environment container `php` yang berjalan melayani request web.
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

- [x] Repositori bersih dari file `.env`, berkas rahasia `secrets/`, file `.key`, dan private key (`git ls-files`).
- [x] Hook `pre-commit` (Gitleaks terpin + filter rahasia) dan `commit-msg` (Conventional Commits) aktif dan executable.
- [x] Struktur kode aplikasi Laravel terisolasi di direktori `src/`; container PHP/Web/Node tidak me-mount root repositori infra.
- [x] Multi-stage Dockerfile PHP menggunakan rilis stabil resmi `php:8.5.12-fpm-alpine` terpin digest.
- [x] Ekstensi `pcntl` tidak terpasang; ekstensi wajib (`pdo_mysql`, `intl`, `bcmath`, `opcache`, `zip`) terverifikasi aktif.
- [x] `disable_functions` aktif pada request web (FPM via `cgi-fcgi`), sedangkan CLI tetap mengizinkan `proc_open`.
- [x] Container web (101:101), php (1000:1000), node (1000:1000), dan mariadbd (mysql 999:999) berjalan 100% sebagai non-root.
- [x] `read_only: true`, `security_opt: [no-new-privileges:true]`, dan `cap_drop: [ALL]` aktif di seluruh container (termasuk node).
- [x] Cache Composer dan NPM terbukti dapat ditulis (*writable*) pada filesystem rootfs read-only.
- [x] Database tidak terhubung ke jaringan `frontend` dan port database tidak diexpose ke host.
- [x] Kata sandi root MariaDB menggunakan Docker Compose secrets, tidak bocor di `docker inspect` atau env `php`, dan root dibatasi ke localhost.
- [x] User `erp_app` terbukti gagal saat menjalankan DDL (`CREATE TABLE`), namun sukses melakukan DML.
- [x] Aturan Nginx diuji dengan berkas nyata (`src/public`) dan kontrol positif `/build/assets/vendor-test.js` lolos 200.
- [x] Seluruh pengujian pada `make verify` lulus (100% PASS).
- [x] Tidak ada framework Laravel/Vue, tabel aplikasi, atau kode bisnis yang terpasang pada Fase 0.

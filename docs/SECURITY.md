# Kebijakan & Standar Hardening Keamanan (Fase 1 - Final)

Dokumen ini mendokumentasikan seluruh lapisan pengamanan (*defense-in-depth*) yang diterapkan pada proyek ERP Distribusi / Trading, mencakup infrastruktur Docker (Fase 0) dan baseline keamanan aplikasi Laravel 13 + Vue 3 (Fase 1).

---

## 1. Lapisan Infrastruktur Docker & Host (Fondasi Fase 0)

### A. Lapisan Host & Git Repository
1. **Pemisahan Kredensial & Deteksi Kebocoran Otomatis**:
   - Berkas `.env` infra dan segala variasinya diabaikan secara ketat oleh `.gitignore` (kecuali template contoh).
   - Direktori `secrets/*` diabaikan sepenuhnya oleh `.gitignore` (hanya `secrets/README.md` yang terlacak), direktori `secrets/` dikunci dengan mode ketat `700`.
   - Hook Git `pre-commit` memeriksa staged diff terhadap pola rahasia (`APP_KEY=base64:`, live password/token/secret tanpa placeholder, berkas dalam `secrets/`, dan private key).
   - Hook `pre-commit` menjalankan pemindaian menyeluruh via Docker image resmi terpin `zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` dengan opsi `:ro` dan `--network none`.
2. **Standardisasi Pesan & Integritas Commit**:
   - Hook `commit-msg` mewajibkan Conventional Commits agar setiap riwayat perubahan dapat dilacak secara terstruktur.
   - Kakas `make hooks` disediakan untuk kemudahan inisialisasi hook Git lokal.
   - Uji fungsional hooks terotomatisasi via `make test-hooks`.

### B. Lapisan Container (Docker Engine & Compose)
1. **Isolasi Struktur Kode Aplikasi (`src/`)**:
   - Kode aplikasi dibatasi hanya hidup di dalam direktori `src/`.
   - Service `php`: bind mount **HANYA** `./src:/var/www/html`.
   - Service `web`: bind mount **HANYA** `./src/public:/var/www/html/public:ro`.
   - Service `node`: bind mount **HANYA** `./src:/var/www/html`.
   - Container aplikasi tidak memiliki akses mount ke root repositori (`.`), `.git`, `.env` infra, konfigurasi Docker (`docker/`), maupun direktori `secrets/`.
2. **User Non-Root pada Runtime**:
   - `web` berjalan sebagai user unprivileged `101:101`.
   - `php` berjalan sebagai user non-root UID/GID 1000 sesuai user WSL host.
   - `node` berjalan sebagai user `${UID:-1000}:${GID:-1000}`.
   - `db` (MariaDB) menjalankan proses daemon `mariadbd` sebagai user khusus `mysql` (UID 999).
3. **Root Filesystem Read-Only (`read_only: true`) & Tmpfs Ketat**:
   - Seluruh service berjalan dengan root filesystem terkunci (read-only).
   - Partisi sementara menggunakan memory `tmpfs` dengan mode hak akses paling ketat yang berfungsi dan pembatasan kapasitas (`size=`):
     - `web`: `/tmp` (1777, 64m), `/var/cache/nginx` (700, UID 101, 64m), `/var/run` (700, UID 101, 16m).
     - `php`: `/tmp` (1777, 256m), `/var/run` (700, UID 1000, GID 1000, 16m).
     - `db`: `/run/mysqld` (755, UID 999, 16m), `/tmp` (1777, 256m), `/var/log/mysql` (755, UID 999, 64m).
     - `node`: `/tmp` (1777, 128m).
   - Cache Composer (`/tmp/composer`) dan NPM (`/home/node/.npm`) dipasang sebagai named volume dengan permission non-root yang terbukti dapat ditulis (*writable*).
4. **Reduksi Linux Capabilities & Anti-Privilege Escalation**:
   - Seluruh container menerapkan `security_opt: [no-new-privileges:true]`.
   - Seluruh container menerapkan `cap_drop: [ALL]`.
   - Service `db` hanya menambahkan kapabilitas minimum absolut yang diwajibkan oleh entrypoint resmi: `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE`.
5. **Pembatasan Port & Segmentasi Jaringan**:
   - Port `web` hanya diexpose ke antarmuka loopback lokal: `127.0.0.1:8080`.
   - Port `node` (Vite) hanya diexpose ke loopback lokal: `127.0.0.1:5174`.
   - Port `db` (MariaDB) **TIDAK** dipublikasikan ke host sama sekali.
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

### E. Lapisan Database (MariaDB 12.3 LTS)
1. **Pemisahan Hak Akses Aplikasi (Principle of Least Privilege)**:
   - `erp_app`: Khusus request web aplikasi. Dibatasi **HANYA** DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Dilarang melakukan DDL (`CREATE`, `ALTER`, `DROP`).
   - `erp_migrator`: Khusus migrasi skema database. Memiliki izin DDL dan DML migrasi, tetapi **TIDAK** memiliki izin `FILE`, `SUPER`, `PROCESS`, atau `GRANT OPTION`.
   - **Kebijakan Injeksi Kredensial**: Berkas `src/.env` milik Laravel hanya berisi kredensial `erp_app`. Kredensial `erp_migrator` disuntikkan secara dinamis hanya saat mengeksekusi `make migrate`.
2. **Pengelolaan Rahasia Root & Pembatasan Host**:
   - Kata sandi root MariaDB dikelola melalui berkas `secrets/db_root_password.txt` (permission 644 di dalam direktori `secrets/` bermode 700) dan dipasang via Docker Compose `secrets` (`MARIADB_ROOT_PASSWORD_FILE`).
   - `MARIADB_ROOT_HOST=localhost` memastikan user `root` tidak dapat melakukan login melalui jaringan TCP/IP.
3. **Parameter Pengerasan MariaDB**:
   - `local_infile = 0` memblokir pembacaan file lokal server melalui query SQL.
   - `symbolic-links = 0` menolak symlink database tables.
   - Healthcheck menggunakan skrip internal `/usr/local/bin/healthcheck.sh --connect --innodb_initialized` tanpa mengekspos kata sandi pada command line arguments.

---

## 2. Hardening Aplikasi Tingkat Lanjut (Fase 1)

### A. Content Security Policy (CSP) & Per-Request Nonce
- Middleware `App\Http\Middleware\SecurityHeaders` secara otomatis membangkitkan nonce unik per-request menggunakan `Vite::useCspNonce()`.
- Nonce disinkronkan langsung ke tag script dan link CSS Blade via directive `@vite`.
- Policy script-src: `'self' 'nonce-...'` (bebas dari `'unsafe-eval'`). Di lingkungan lokal, mengizinkan koneksi WebSocket dan script origin Vite dev server `127.0.0.1:5174`.
- Policy style-src:
  - Lingkungan non-local (production/staging/testing): Strictly `'self'`. Tanpa `'unsafe-inline'` dan tanpa nonce. Seluruh styling dimuat melalui berkas stylesheet statis hasil kompilasi Vite (`<link rel="stylesheet">`).
  - Lingkungan lokal (`APP_ENV=local`): Mengizinkan `'self' 'unsafe-inline' http://127.0.0.1:5174` semata-mata karena Vite development server menyuntikkan tag `<style>` secara dinamis untuk Hot Module Replacement (HMR).
  - Larangan pencampuran `'unsafe-inline'` dan `nonce`: Sesuai spesifikasi W3C CSP Level 2 dan Level 3, browser modern secara otomatis **mengabaikan** `'unsafe-inline'` jika terdapat `nonce` atau `hash` pada direktif yang sama. Oleh karena itu, direktif `style-src` dilarang keras memuat nonce berdampingan dengan `'unsafe-inline'`.
  - Penanganan Indikator Progress Inertia: Konfigurasi `progress` pada `createInertiaApp` menetapkan `includeCSS: false` dan memindahkan seluruh styling NProgress ke `src/resources/css/app.css`, sehingga mengeliminasi injeksi tag `<style>` inline pada saat runtime. Tidak ada kompilasi runtime Tailwind di browser.
- Policy object-src: `'none'`, base-uri `'self'`, form-action `'self'`, frame-ancestors `'none'`.
- Directive `upgrade-insecure-requests` otomatis ditambahkan pada lingkungan production.

### B. Header Keamanan Tambahan
- `X-Content-Type-Options: nosniff` (mencegah MIME type sniffing).
- `X-Frame-Options: DENY` (mencegah serangan Clickjacking).
- `Referrer-Policy: strict-origin-when-cross-origin` (melindungi privasi query string pada referer).
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()` (menonaktifkan API peramban yang sensitif).
- `Cross-Origin-Opener-Policy: same-origin` (COOP mengisolasi konteks browsing).
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` (HSTS pada lingkungan production).

### C. Keamanan Sesi & Cookie
- `SESSION_DRIVER=database` (disimpan terisolasi di database).
- `SESSION_ENCRYPT=true` (payload sesi dienkripsi di level framework).
- `SESSION_HTTP_ONLY=true` (mencegah pembacaan cookie sesi via JavaScript/XSS).
- `SESSION_SAME_SITE=lax` (mitigasi serangan CSRF).
- `SESSION_SECURE_COOKIE=true` pada production.

### D. Eloquent & Database Safety Baseline
- `Model::shouldBeStrict(! app()->isProduction())`: Mencegah N+1 lazy loading dan silent mass-assignment issues.
- `DB::prohibitDestructiveCommands(app()->isProduction())`: Memblokir perintah destruktif seperti `migrate:fresh` di production.
- `Date::use(CarbonImmutable::class)`: Mencegah bug mutasi tanggal yang tidak disengaja.

### E. Custom Exception Handling & Penanganan Error
- Di lingkungan production, penanganan error (`bootstrap/app.php`) mencegat status code 403, 404, 419, 429, 500, dan 503.
- Mengembalikan respons JSON bersih untuk permintaan API/Inertia atau tampilan Blade minimalis tanpa pernah membocorkan stack trace, query SQL, atau path sistem internal.

### F. Pengujian Statis Keamanan & Aturan Arsitektur Pest (Pest Arch Rules)
Pengujian keamanan statis ditegakkan melalui dua mekanisme pelengkap:
1. **Pest Arch Rules (`tests/Arch/SecurityArchTest.php`):**
   - Melarang fungsi berbahaya di dalam kode `app/`: `eval`, `create_function`, `extract`, `unserialize`, `assert`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `dd`, `dump`, `var_dump`.
   - Melarang fasad atau komponen proses eksekusi OS: `Illuminate\Support\Facades\Process` dan `Symfony\Component\Process\Process`.
2. **Scanner Token Statis (`tests/Feature/StaticSecurityScanTest.php`):**
   - Memindai seluruh berkas PHP pada direktori `app/`, `routes/`, `database/`, `config/`, dan `bootstrap/` menggunakan fungsi bawaan `token_get_all`.
   - Mendeteksi pemanggilan fungsi global berbahaya (bukan pemanggilan method yang sah), operator eksekusi backtick (`` `...` ``), dan konstruksi bahasa `eval`.
   - Mendeteksi pemanggilan raw SQL (`DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `groupByRaw`, `fromRaw`, `DB::unprepared`, `DB::statement`) yang argumen pertamanya BUKAN string literal statis murni (mendeteksi konkatenasi, variabel, maupun interpolasi string).
   - Diuji secara reguler dengan tes negatif (fixtures) untuk membuktikan scanner tidak memberikan hasil lolos palsu (*false negatives*).
   - **Keterbatasan Scanner:** Scanner statis berbasis token ini menganalisis kode pada level leksikal tanpa inferensi data-flow dinamis atau *taint analysis*. Pola berbahaya yang disamarkan melalui variable function (misalnya `$func = 'sys'.'tem'; $func(...)`), `call_user_func`, eksekusi via Reflection, atau deserialisasi dinamis tidak dapat dicegat sepenuhnya oleh scanner token. Karena itu, scanner ini berfungsi sebagai lini pertahanan statis (*defense-in-depth*) yang diperkuat oleh pembatasan runtime di PHP-FPM (`disable_functions`), isolasi container Linux non-root, `read_only: true`, dan `cap_drop: ALL`.

### G. Penanganan Error & Halaman Respons Statis
- Seluruh header keamanan (`SecurityHeaders`) didaftarkan sebagai middleware global (`$middleware->append(SecurityHeaders::class)`), memastikan respons route yang tidak ditemukan (404) dan exception sistem lainnya tetap membawa header keamanan lengkap (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, COOP).
- Berkas template error `resources/views/errors/minimal.blade.php` tidak memuat tag `<style>` inline maupun atribut `style=`. Seluruh styling dipindahkan ke berkas statis `public/error.css` yang dilayani langsung oleh web server Nginx dari origin `'self'` tanpa ketergantungan pada manifest Vite.

### H. Pengelolaan Dependensi Aman & Self-Hosted Assets
- Dependensi Composer dilindungi oleh `roave/security-advisories:dev-latest`.
- Audit otomatis `composer audit` dan `npm audit` 100% bebas kerentanan (0 vulnerabilities).
- Seluruh aset font (`@fontsource-variable/inter`) disimpan dan dilayani mandiri dari server lokal, dilarang keras memuat font dari Google Fonts atau CDN publik.
- Berkas `.npmrc` mengaktifkan `ignore-scripts=true`.

---

## 3. Matriks Ancaman & Mitigasi Keamanan Aplikasi

| Vektor Ancaman | Dampak Potensial | Mekanisme Mitigasi Fase 1 | Verifikasi / Bukti Pengujian |
|---|---|---|---|
| **Cross-Site Scripting (XSS)** | Pencurian sesi, defacement, manipulasi DOM | Content Security Policy dengan per-request nonce unik, pembatasan script-src tanpa `unsafe-eval`, cookie HttpOnly | `SecurityHeadersTest.php`, `verify-hardening.sh` (Butir 14) |
| **Cross-Site Request Forgery (CSRF)** | Eksekusi transaksi tanpa otorisasi pengguna | Cookie `SameSite=lax`, token CSRF Laravel (`VerifyCsrfToken`), form-action `'self'` | `ConfigSafetyTest.php`, `verify-hardening.sh` (Butir 14) |
| **SQL Injection & Schema Tampering** | Kerusakan atau penghapusan skema database | Koneksi default `erp_app` dilarang keras DDL (CREATE/DROP), `DB::prohibitDestructiveCommands()`, PDO prepared statements | `DatabasePrivilegeTest.php`, `verify-hardening.sh` (Butir 8) |
| **Clickjacking** | Pengguna dijebak mengklik tombol tersembunyi | Header `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` | `SecurityHeadersTest.php`, `verify-hardening.sh` (Butir 14) |
| **Information Disclosure** | Pembocoran credential, database path, atau source | Error handler produksi tanpa stack trace, server tokens & PHP headers tersembunyi, Nginx blokir `.env` dan `storage/logs` | `SmokeTest.php`, `verify-hardening.sh` (Butir 5 & 14) |
| **Remote Code Execution (RCE)** | Pengambilalihan server via fungsi berbahaya | `disable_functions` pada PHP-FPM, Pest Arch test melarang `eval`, `exec`, `system`, dll di `app/` | `SecurityArchTest.php`, `verify-hardening.sh` (Butir 6) |
| **Supply Chain Attacks** | Pemasangan dependensi berbahaya atau bervulnerabilitas | `roave/security-advisories`, `ignore-scripts=true` pada `.npmrc`, Docker images dipin dengan SHA256 digest | `composer audit`, `npm audit`, `verify-hardening.sh` |

---

## 4. Checklist Review Arsitek / Pengawas (Fase 1)

- [x] Repositori bersih dari file `.env`, berkas rahasia `secrets/`, file `.key`, dan private key (`git ls-files`).
- [x] Hook `pre-commit` (Gitleaks terpin + filter rahasia) dan `commit-msg` (Conventional Commits) aktif dan executable.
- [x] Skeleton Laravel 13 bersih di `src/`, terhubung ke MariaDB (2 koneksi: `erp_app` dan `erp_migrator`), tanpa jejak SQLite.
- [x] Seluruh file PHP baru menerapkan `declare(strict_types=1);`.
- [x] `Model::shouldBeStrict()`, `Date::use(CarbonImmutable::class)`, `DB::prohibitDestructiveCommands()`, dan `Vite::useCspNonce()` terkonfigurasi di `AppServiceProvider`.
- [x] Middleware `SecurityHeaders` aktif di web group dengan per-request nonce CSP dan seluruh header keamanan wajib.
- [x] Font Inter di-self-host dari paket `@fontsource-variable/inter` tanpa ketergantungan CDN eksternal.
- [x] Layout dasar `AppLayout.vue` responsif (desktop collapsible sidebar, mobile drawer, WCAG AA, focus-visible) dan `Dashboard.vue` beroperasi normal.
- [x] Seluruh tes otomatis Pest (Unit, Feature, Arch) menggunakan MariaDB nyata lulus 100% (`make test`).
- [x] Static analysis Larastan level 6 lulus 0 error (`make analyse`).
- [x] Pengecekan gaya kode Pint lulus 0 pelanggaran (`make lint`).
- [x] Linting frontend ESLint lulus 0 error (`docker compose --profile tools run --rm node npm run lint`).
- [x] Audit dependensi Composer dan NPM bersih dari celah keamanan (0 vulnerabilities).
- [x] Skrip verifikasi keamanan `make verify` lulus 100% (89 PASS / 0 FAIL).

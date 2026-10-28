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
4. **Proteksi DoS & Rate Limiting Berlapis**:
   - Mengaktifkan `limit_req_zone` untuk rate limiting umum IP klien.
   - Zona Khusus Autentikasi (`login_limit`): Membatasi metode POST pada `/login` berbasis alamat IP soket TCP fisik (`$binary_remote_addr`) dengan parameter `rate=10r/m` dan `burst=5 nodelay;`.
   - Mengonfigurasi `limit_req_status 429;` agar Nginx langsung mengembalikan kode status HTTP 429 saat batas terlampaui mendahului FastCGI PHP.
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
4. **Alokasi Sumber Daya Process Manager (PM) & Pencegahan OOM (Revisi Fase 3.1)**:
   - Dikonfigurasi eksplisit via `docker/php/conf/php-fpm-hardening.conf` (di-mount ke container):
     - `pm = dynamic`
     - `pm.max_children = 4`
     - `pm.start_servers = 2`
     - `pm.min_spare_servers = 1`
     - `pm.max_spare_servers = 3`
     - `pm.max_requests = 500` (mencegah akumulasi memory leak dengan mendaur ulang worker)
     - `request_terminate_timeout = 30s`
   - **Perhitungan Headroom Memori (Memory Headroom vs Argon2id)**:
     - Batas RAM container PHP: 512 MB (`mem_limit: 512m` pada `compose.yaml`).
     - Komputasi hashing Argon2id memerlukan alokasi `memory_cost=65536` (~64 MB per hash).
     - Alokasi memori per worker aktif di bawah beban hashing: ~30 MB (Laravel runtime + framework) + ~64 MB (Argon2id peak) = ~94-95 MB.
     - Puncak beban paralel (seluruh 4 worker mengeksekusi Argon2id secara bersamaan): `4 * 95 MB = 380 MB`.
     - Headroom memori tersisa: `512 MB - 380 MB = 132 MB` (~26% buffer aman untuk sistem operasi container, ekstensi PHP, buffer I/O, dan OPcache).
     - Menjamin container `erp-trading-php` kebal dari terminasi OOM-killer kernel Linux (`docker inspect erp-trading-php --format '{{.State.OOMKilled}}'` = `false`).

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

### H. Integritas Database, Hak Akses, & Trigger Anti-Mutasi (Fase 2)
1. **Pemisahan Hak Akses Database (Least Privilege Principle):**
   - User aplikasi (`erp_app`) strictly berhak hanya pada operasi DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Dilarang keras memiliki hak DDL (`CREATE`, `DROP`, `ALTER`), hak administratif (`SUPER`, `PROCESS`, `FILE`, `GRANT OPTION`), maupun hak `TRIGGER`.
   - User migrator (`erp_migrator`) memiliki hak DDL dan hak `TRIGGER` untuk menjalankan migrasi skema dan membuat trigger integritas. Dilarang keras memiliki hak `SUPER`, `FILE`, `PROCESS`, atau `GRANT OPTION`.
   - **Analisis Risiko & Mitigasi erp_migrator**:
     - *Risiko Teknis*: Dalam MariaDB, hak DDL (`DROP` & `TRIGGER`) yang dimiliki `erp_migrator` secara inheren memungkinkan operasi `TRUNCATE TABLE` atau `DROP TRIGGER`. Selain itu, trigger append-only dibuat dengan `DEFINER = erp_migrator@%`.
     - *Mitigasi Arsitektural*:
       1. Kredensial `erp_migrator` **TIDAK PERNAH** disimpan di dalam berkas konfigurasi `src/.env` milik Laravel maupun environment container runtime (`erp-trading-php`, `erp-trading-web`).
       2. Kredensial `erp_migrator` disuntikkan secara dinamis dari file `.env` host hanya pada saat eksekusi migrasi skema dijalankan (`make migrate` atau deploy pipeline).
       3. Kontainer web/aplikasi yang melayani request pengguna publik hanya memegang kredensial `erp_app` yang strictly DML, sehingga penyerang yang berhasil mengeksploitasi aplikasi web tidak memiliki privilege untuk melakukan `TRUNCATE`, `DROP TRIGGER`, atau menembus trigger append-only.
2. **Trigger Integritas Ledger Append-Only:**
   - Tabel `audit_logs` dan `stock_movements` dilindungi oleh trigger database tingkat mesin (`BEFORE UPDATE` dan `BEFORE DELETE`) yang mengeksekusi `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '<tabel> is append-only'`.
   - Trigger ini berlaku untuk SEMUA user, termasuk `erp_migrator` dan user aplikasi, menjamin riwayat mutasi stok dan jejak audit tidak dapat diubah atau dimanipulasi secara retrospektif oleh siapa pun.
3. **Kebijakan TRUSTED_PROXIES & Pencegahan Spoofing IP (Revisi Fase 3.1):**
   - **Analisis Kerentanan & Akar Masalah**:
     Pada Fase 3 awal, `bootstrap/app.php` mempercayai subnet IP privat RFC 1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) dan loopback `127.0.0.1`. Dalam arsitektur container Docker (bridge network), Nginx terhubung ke PHP-FPM melalui alamat gateway privat (rentang `172.x.x.x`). Akibatnya, Laravel menganggap Nginx sebagai reverse proxy tepercaya dan secara membabi-buta membaca header `X-Forwarded-For` yang dikirimkan oleh klien luar/penyerang. Hal ini memungkinkan penyerang melewati pembatasan laju login secara total dengan memalsukan header `X-Forwarded-For` acak pada tiap permintaan.
   - **Kebijakan Default Zero-Trust**:
     Konfigurasi bawaan `TRUSTED_PROXIES` adalah **KOSONG** (`""` atau `null`). Seluruh rentang IP privat hardcoded telah dihapus secara menyeluruh dari kode basis (`bootstrap/app.php` dan `config/app.php`). Konfigurasi dikelola secara tersentralisasi melalui `src/config/trustedproxy.php`.
   - **Kebijakan Produksi (Production Policy)**:
     Nilai `TRUSTED_PROXIES` HANYA boleh diisi jika terdapat reverse proxy atau edge load balancer NYATA di depan Nginx (misal Cloudflare, AWS ALB, atau Nginx Ingress). Pengisian hanya boleh memuat alamat IP atau CIDR spesifik milik reverse proxy tersebut, dilarang mencakup seluruh rentang privat umum.
   - **Template & Dokumentasi Lingkungan**:
     Berkas template `.env.example` dan `.env.production.example` memuat `TRUSTED_PROXIES=` kosong disertai catatan peringatan keamanan eksplisit.

### I. Privasi Kunci Cache Rate Limiter & Deduplikasi Audit (Revisi Fase 3.1)
- **Hashing Kunci Cache**: Alamat email pada kunci pembatas laju login dienkripsi satu arah menggunakan algoritma SHA-256: `login:email:hash('sha256', strtolower(trim($email)))`. Kunci cache yang tersimpan di tabel `cache` MariaDB tidak memuat email teks mentah (*plaintext email*), mencegah kebocoran data pengguna (*data privacy*).
- **Deduplikasi Log Audit (Anti-Storage DoS)**: Peristiwa penolakan berulang (`login.throttled` dan `access.denied`) dideduplikasi secara otomatis selama 60 detik melalui atomic lock cache `Cache::add()`, mencegah banjir penyimpanan disk database dari ribuan request serangan brute force.

### J. Pengelolaan Dependensi Aman & Self-Hosted Assets
- Dependensi Composer dilindungi oleh `roave/security-advisories:dev-latest`.
- Audit otomatis `composer audit` dan `npm audit` 100% bebas kerentanan (0 vulnerabilities).
- Seluruh aset font (`@fontsource-variable/inter`) disimpan dan dilayani mandiri dari server lokal, dilarang keras memuat font dari Google Fonts atau CDN publik.
- Berkas `.npmrc` mengaktifkan `ignore-scripts=true`.

### J. Pemindai Keamanan Statis (Static Security Scanner) & Keterbatasan Deteksi
Pemindai keamanan statis berbasis token AST (`token_get_all`) diuji secara berkala melalui `StaticSecurityScanTest.php` untuk memindai seluruh direktori `app/`, `routes/`, `database/`, `config/`, dan `bootstrap/`. Pemindai memvalidasi ketiadaan fungsi berbahaya (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `unserialize`, `assert`, `create_function`, `extract`, `call_user_func`, `call_user_func_array`, `forward_static_call`, `forward_static_call_array`, `pcntl_exec`, `phpinfo`, `highlight_file`, `show_source`), backtick execution, argumen non-literal pada SQL mentah (`raw`, `whereRaw`, `statement`, dll), serta penyertaan dinamis (`include`, `require`, `include_once`, `require_once` non-literal).

**Keterbatasan Pemindai Statis**:
1. **Pemanggilan Fungsi Melalui Variabel (`$f()`)**: Pemindai berbasis token statis tidak dapat melacak nilai runtime variabel dinamis yang menampung nama fungsi (misalnya `$func = 'exec'; $func('ls');`).
2. **Callable Berupa String**: Pemanggilan melalui fungsi bawaan PHP yang menerima callback nama fungsi string (misalnya `array_map('system', $commands)` atau `usort($data, 'exec')`) tidak terdeteksi oleh parser token sederhana.
3. **Pemanggilan Metode Dinamis**: Invokasi objek dengan nama metode dinamis (`$object->$methodName()`).
4. **Refleksi Dinamis**: Instansiasi dan eksekusi via PHP Reflection API dinamis.

**Mitigasi Berlapis Terhadap Keterbatasan Pemindai**:
- Hardening runtime PHP-FPM (`disable_functions` memuat `exec, passthru, shell_exec, system, proc_open, popen, pcntl_exec`) yang mematikan eksekusi di tingkat binary C PHP engine terlepas dari cara fungsi tersebut dipanggil.
- Hardening container (`read_only: true`, `cap_drop: ALL`, `no-new-privileges: true`) yang membatasi kemampuan proses sistem operasi.
- Pest Architectural Test (`SecurityArchTest.php`) yang melarang penggunaan process execution component di seluruh kode aplikasi.


---

## 3. Matriks Ancaman & Mitigasi Keamanan Aplikasi

| Vektor Ancaman | Dampak Potensial | Mekanisme Mitigasi & Pengamanan | Verifikasi / Bukti Pengujian |
|---|---|---|---|
| **Cross-Site Scripting (XSS)** | Pencurian sesi, defacement, manipulasi DOM | Content Security Policy dengan per-request nonce unik, pembatasan script-src tanpa `unsafe-eval`, cookie HttpOnly, Vue no-v-html | `SecurityHeadersTest.php`, `verify-hardening.sh` (Butir 14), `npm run lint` |
| **Cross-Site Request Forgery (CSRF)** | Eksekusi transaksi tanpa otorisasi pengguna | Cookie `SameSite=lax`, token CSRF Laravel (`VerifyCsrfToken`), form-action `'self'` | `ConfigSafetyTest.php`, `AuthenticationTest.php`, `verify-hardening.sh` (Butir 16) |
| **SQL Injection & Schema Tampering** | Kerusakan atau penghapusan skema database | Koneksi default `erp_app` dilarang keras DDL (CREATE/DROP), `DB::prohibitDestructiveCommands()`, PDO prepared statements | `DatabasePrivilegeTest.php`, `verify-hardening.sh` (Butir 8) |
| **Clickjacking** | Pengguna dijebak mengklik tombol tersembunyi | Header `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` | `SecurityHeadersTest.php`, `verify-hardening.sh` (Butir 14) |
| **Information Disclosure** | Pembocoran credential, database path, atau source | Error handler produksi tanpa stack trace, server tokens & PHP headers tersembunyi, Nginx blokir `.env` dan `storage/logs` | `SmokeTest.php`, `verify-hardening.sh` (Butir 5 & 14) |
| **Remote Code Execution (RCE)** | Pengambilalihan server via fungsi berbahaya | `disable_functions` pada PHP-FPM, Pest Arch test melarang `eval`, `exec`, `system`, dll di `app/` | `SecurityArchTest.php`, `verify-hardening.sh` (Butir 6) |
| **Supply Chain Attacks** | Pemasangan dependensi berbahaya atau bervulnerabilitas | `roave/security-advisories`, `ignore-scripts=true` pada `.npmrc`, Docker images dipin dengan SHA256 digest | `composer audit`, `npm audit`, `verify-hardening.sh` |
| **Brute Force & Credential Stuffing** | Pembajakan akun pengguna via pencocokan massal | Rate Limiter database (5 kegagalan/menit per email+IP, percobaan ke-6 HTTP 429), Argon2id memory-hard password hashing | `AuthenticationTest.php`, `verify-hardening.sh` (Butir 16) |
| **Account Enumeration** | Mengidentifikasi akun valid via perbedaan respon error | Pesan error generik `"Email atau kata sandi salah."`, email mentah di-hash SHA-256 pada audit log | `AuthenticationTest.php`, `AuditLogTest.php` |
| **Session Hijacking & Fixation** | Pencurian cookie sesi aktif atau penyusupan session ID | Regenerasi ID sesi saat login, `HttpOnly`, `SameSite=Lax`, idle timeout 120 menit, absolute session lifetime 480 menit | `AuthenticationTest.php`, `verify-hardening.sh` (Butir 16) |
| **Privilege Escalation** | Pengguna mengakses modul atau data di luar haknya | Otorisasi RBAC via database (98 permission, 7 system roles), `Gate::before`, in-memory permission caching, middleware `can:<permission>` | `AuthorizationTest.php` |
| **Inactive User Access** | Pengguna yang dinonaktifkan tetap menggunakan sesi lama | Middleware `EnsureUserIsActive` memaksa logout pengguna non-aktif pada setiap request berikutnya | `AuthenticationTest.php` |
| **Credential Leakage in Logs** | Kata sandi atau token sensitif tersimpan di riwayat audit | Sanitasi rekursif regex `/password\|token\|secret\|key/i` pada `AuditLogger` | `AuditLogTest.php` |
| **IP Spoofing & XFF Rate Limit Bypass** | Penyerang memalsukan `X-Forwarded-For` untuk melewati batas laju login | `TRUSTED_PROXIES` kosong bawaan (tanpa CIDR privat), ditambah Nginx `limit_req_zone` berbasis TCP socket `$binary_remote_addr` (status 429) | `AuthenticationTest.php`, `ConfigSafetyTest.php`, `probe-login-throttle.sh`, `verify-hardening.sh` (Butir 17) |
| **Current Password Brute Force** | Penyerang dengan sesi aktif menebak `current_password` di form ganti password | Rate limiting verifikasi kata sandi saat ini sebelum `Hash::check` (5 gagal/menit per user, 20 per IP), reset otomatis saat berhasil | `AuthenticationTest.php` |
| **Audit Log Storage Flooding (DoS)** | Ribuan percobaan salah membanjiri tabel `audit_logs` | Deduplikasi otomatis event `login.throttled` dan `access.denied` dengan jendela 60 detik via atomic lock cache | `AuditLogTest.php` |
| **PHP-FPM OOM Exhaustion via Argon2id** | Permintaan login paralel menghabiskan RAM container PHP (512MB) | Konfigurasi eksplisit `pm = dynamic`, `max_children = 4` (puncak 380MB vs 512MB limit, sisa buffer 132MB aman) | `f31-r8-load-test.txt`, `verify-hardening.sh` (Butir 17) |

---

## 4. Checklist Review Arsitek / Pengawas (Fase 3 & Revisi Fase 3.1)

- [x] Repositori bersih dari file `.env`, berkas rahasia `secrets/`, file `.key`, dan private key (`git ls-files`).
- [x] Hook `pre-commit` (Gitleaks terpin + filter rahasia) dan `commit-msg` (Conventional Commits) aktif dan executable.
- [x] Skeleton Laravel 13 bersih di `src/`, terhubung ke MariaDB (2 koneksi: `erp_app` dan `erp_migrator`), tanpa jejak SQLite.
- [x] Seluruh file PHP baru menerapkan `declare(strict_types=1);`.
- [x] `Model::shouldBeStrict()`, `Date::use(CarbonImmutable::class)`, `DB::prohibitDestructiveCommands()`, dan `Vite::useCspNonce()` terkonfigurasi di `AppServiceProvider`.
- [x] Middleware `SecurityHeaders` aktif di web group dengan per-request nonce CSP dan seluruh header keamanan wajib.
- [x] Model `User`, `Role`, `Permission` terkonfigurasi dengan relasi Many-to-Many (`role_user`, `permission_role`).
- [x] Katalog 98 permission dan 7 peran sistem terpasang idempoten via `PermissionSeeder` dan `RoleSeeder`.
- [x] Hashing Argon2id terverifikasi pada PHP-FPM dan PHP-CLI (`password_algos()`).
- [x] Rate limiting login berbasis database (5 percobaan/menit, percobaan ke-6 HTTP 429).
- [x] Session timeouts terpasang: idle 120 menit dan absolute lifetime 480 menit (`EnforceSessionTimeouts`).
- [x] Otorisasi `Gate::before` terintegrasi in-memory caching pada model `User` bebas dari issue N+1 query.
- [x] Layanan `AuditLogger` mencatat peristiwa keamanan secara aman ke tabel `audit_logs` (*append-only*).
- [x] Halaman login (`Login.vue`) dan ganti kata sandi (`Password.vue`) mematuhi CSP, bebas inline style/script, WCAG AA.
- [x] Perintah Artisan aman: `erp:admin:create`, `erp:user:reset-password`, `erp:user:set-active`, `erp:permissions:sync`.
- [x] Seluruh tes otomatis Pest lulus 100% (`make test`).
- [x] Static analysis Larastan level 6 lulus 0 error (`make analyse`).
- [x] Pengecekan gaya kode Pint lulus 0 pelanggaran (`make lint`).
- [x] Linting frontend ESLint lulus 0 error (`docker compose --profile tools run --rm node npm run lint`).
- [x] Audit dependensi Composer dan NPM bersih dari celah keamanan (0 vulnerabilities).
- [x] Skrip verifikasi keamanan `make verify` lulus 100% (115 PASS / 0 FAIL).
- [x] Font Inter di-self-host dari paket `@fontsource-variable/inter` tanpa ketergantungan CDN eksternal.
- [x] Layout dasar `AppLayout.vue` responsif (desktop collapsible sidebar, mobile drawer, WCAG AA, focus-visible) dan `Dashboard.vue` beroperasi normal.
- [x] **[Revisi Fase 3.1]** Anti-Spoofing IP & XFF: `TRUSTED_PROXIES` kosong secara default, tidak ada rentang privat hardcoded di `bootstrap/app.php` maupun `config/app.php`.
- [x] **[Revisi Fase 3.1]** Nginx memuat zona rate limiting `zone=login_limit:10m` khusus POST dan `limit_req_status 429`.
- [x] **[Revisi Fase 3.1]** Skrip probe anti-spoofing `scripts/probe-login-throttle.sh` memverifikasi skenario kontrol dan skenario manipulasi header XFF tertahan pada percobaan ke-6.
- [x] **[Revisi Fase 3.1]** Rate limiting verifikasi kata sandi saat ini (`PUT /account/password`) aktif sebelum `Hash::check` dijalankan.
- [x] **[Revisi Fase 3.1]** Eksepsi terdedikasi `TooManyAttemptsException` menghasilkan format yang tepat: Inertia 303 Redirect + error flash banner (tanpa popup modal error) dan API 429 JSON.
- [x] **[Revisi Fase 3.1]** Deduplikasi log audit 60 detik untuk `login.throttled` dan `access.denied` mencegah serangan DoS penyimpanan.
- [x] **[Revisi Fase 3.1]** Perintah `erp:admin:create` atomik dalam transaksi basis data dan memvalidasi keberadaan peran `super_admin`.
- [x] **[Revisi Fase 3.1]** Aturan validasi kata sandi tersentralisasi tunggal melalui `App\Support\PasswordPolicy`.
- [x] **[Revisi Fase 3.1]** Privasi cache: kunci rate limiter login menggunakan hash SHA-256 (`login:email:<sha256>`), bebas dari email mentah di basis data.
- [x] **[Revisi Fase 3.1]** Konfigurasi sumber daya PHP-FPM eksplisit (`pm.max_children = 4`, `pm.max_requests = 500`) dengan alokasi memori terkendali dan terverifikasi `OOMKilled=false`.


---

## 5. Fondasi Backend & Mitigasi Keamanan Lanjutan (Fase 4)

### A. Pencegahan Log Flood & Kebocoran Stack Trace (*Log Flood Prevention*)
1. **Masalah**:
   - Pengecualian operasional bisnis yang normal (seperti salah password berulang, transisi status yang tidak valid, atau stok tidak cukup) jika dilaporkan ke log framework akan menghasilkan ribuan baris log berstatus ERROR lengkap dengan *stack trace*.
   - Hal ini berisiko menyebabkan *disk exhaustion* (DoS kapasitas media penyimpanan), memperlambat I/O container, serta mengaburkan insiden teknis kritis yang sesungguhnya.
2. **Solusi & Mitigasi**:
   - Seluruh hierarki exception bisnis (`BusinessRuleException`) serta exception pembatasan laju (`TooManyAttemptsException`) didaftarkan ke `$exceptions->dontReport(...)` di `bootstrap/app.php`.
   - Exception ini tidak menulis stack trace ke file log framework, namun tetap dikonversi menjadi respons pengguna yang ramah dan aman via `RendersAsFormError`.
   - Kegagalan keamanan yang perlu diaudit tetap dicatat secara aman dan terstruktur melalui `AuditLogger` ke tabel `audit_logs` dengan mekanisme *rate limiting / deduplication* terpasang.
   - Terverifikasi otomatis pada skrip verifikasi `make verify` (Butir 18).

### B. Resolusi IP Klien Tepercaya (*Trusted Proxy Configuration*)
1. **Konfigurasi Terpusat**:
   - Resolusi IP tepercaya diatur secara terpusat melalui `config/trustedproxy.php` yang membaca variabel lingkungan `TRUSTED_PROXIES`.
   - Secara default `TRUSTED_PROXIES` bernilai kosong (`null`), sehingga Laravel sepenuhnya mengabaikan header `X-Forwarded-For` dari request HTTP dan secara eksklusif menggunakan IP FastCGI `REMOTE_ADDR` yang diteruskan oleh Nginx.
2. **Verifikasi Perilaku (*Behavioral Verification*)**:
   - Diuji dan diverifikasi secara perilaku melalui `tests/Feature/TrustedProxyTest.php`:
     - Skenario default (`TRUSTED_PROXIES` kosong): Request dengan manipulasi header `X-Forwarded-For` tetap menghasilkan `request()->ip()` sesuai alamat IP peer koneksi (`REMOTE_ADDR`), menolak IP palsu.
     - Skenario proxy terpercaya (IP gateway/load balancer dikonfigurasi eksplisit): Header `X-Forwarded-For` dari proxy terpercaya dievaluasi secara benar.

### C. Integritas Data & Proteksi Injeksi
1. **Pencegahan Injeksi SQL pada Query Builder Listing (`ListQuery`)**:
   - Seluruh kolom pengurutan (`sort`) dan penyaringan (`filter`) divalidasi terhadap *whitelist* kolom model yang diizinkan. Parameter yang tidak terdaftar akan diabaikan tanpa memicu SQL error.
   - Karakter wildcard SQL `LIKE` (`%`, `_`, `\`) disanitasi secara otomatis menggunakan *escaping* berbasis *escape character* eksplisit (`!`) sehingga input pencarian pengguna selalu dicocokkan secara literal.
2. **Presisi Aritmetika Moneter (`Decimal`)**:
   - Penggunaan tipe floating-point dilarang keras untuk mencegah kesalahan perhitungan nilai transaksi moneter. Seluruh perhitungan menggunakan `App\Support\Decimal` berbasis `bcmath` dengan representasi `string`.



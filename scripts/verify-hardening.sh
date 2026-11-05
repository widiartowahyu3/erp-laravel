#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PASSED_COUNT=0
FAILED_COUNT=0

pass() {
    echo -e "${GREEN}[PASS]${NC} $1"
    PASSED_COUNT=$((PASSED_COUNT + 1))
}

fail() {
    echo -e "${RED}[FAIL]${NC} $1"
    FAILED_COUNT=$((FAILED_COUNT + 1))
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

echo "================================================================="
echo "            SECURITY & HARDENING VERIFICATION SUITE              "
echo "================================================================="

# Source .env
if [ ! -f .env ]; then
    echo "ERROR: .env file not found. Run make / create .env first."
    exit 1
fi

if [ ! -f secrets/db_root_password.txt ]; then
    echo "ERROR: secrets/db_root_password.txt not found."
    exit 1
fi

DB_ROOT_SECRET="$(cat secrets/db_root_password.txt | tr -d '\r\n')"

# Non-destructive tracking for temporary test files and directories
CREATED_FILES=()
CREATED_DIRS=()
PROBE_ID="probe_${$}_${RANDOM}"
PROBE_FPM_FILE="/tmp/fpm_probe_${PROBE_ID}.php"
PROBE_COMPOSER_WRITE="/tmp/composer/test_write_${PROBE_ID}"
PROBE_NPM_WRITE="/home/node/.npm/test_write_${PROBE_ID}"

safe_create_dir() {
    local dir="$1"
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        CREATED_DIRS+=("$dir")
    fi
}

safe_create_file() {
    local file="$1"
    local content="$2"
    if [ ! -f "$file" ]; then
        echo "$content" > "$file"
        CREATED_FILES+=("$file")
    fi
}

cleanup() {
    info "Running non-destructive cleanup of temporary test files and probes..."
    for f in "${CREATED_FILES[@]:-}"; do
        if [ -n "$f" ] && [ -f "$f" ]; then
            rm -f "$f" 2>/dev/null || true
        fi
    done

    # Remove directories created exclusively by this script (reverse order)
    if [ ${#CREATED_DIRS[@]} -gt 0 ]; then
        for ((idx=${#CREATED_DIRS[@]}-1; idx>=0; idx--)); do
            local d="${CREATED_DIRS[idx]}"
            if [ -d "$d" ]; then
                rmdir "$d" 2>/dev/null || rm -rf "$d" 2>/dev/null || true
            fi
        done
    fi

    # Container probe cleanup
    docker compose exec -T php rm -f "$PROBE_FPM_FILE" 2>/dev/null || true
    docker compose exec -T php rm -f "$PROBE_COMPOSER_WRITE" 2>/dev/null || true
    docker compose --profile tools exec node rm -f "$PROBE_NPM_WRITE" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Safely load .env without clashing with Bash's built-in readonly $UID
while IFS='=' read -r key val || [ -n "$key" ]; do
    [[ "$key" =~ ^[[:space:]]*# ]] && continue
    [ -z "$key" ] && continue
    key=$(echo "$key" | xargs)
    val=$(echo "$val" | xargs)
    if [ "$key" != "UID" ]; then
        export "$key=$val"
    fi
done < .env

# Ensure stack is running
info "Checking Docker Compose services status..."
if ! docker compose ps --services --filter "status=running" | grep -q "web"; then
    info "Starting containers for verification..."
    docker compose up -d
fi

# Ensure node service is also started via tools profile for inspection
docker compose --profile tools up -d node >/dev/null 2>&1

# Wait for healthy services
info "Waiting for healthy status on web, php, db, scheduler..."
for s in web php db scheduler; do
    retries=30
    until [ "$retries" -le 0 ]; do
        status=$(docker inspect "erp-trading-${s}" --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' 2>/dev/null || true)
        if [ "$status" = "healthy" ] || [ "$status" = "running" ]; then
            break
        fi
        sleep 1
        retries=$((retries - 1))
    done
done

# ------------------------------------------------------------------------------
# 1. Container web, php, node berjalan non-root (id -u != 0)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 1: Verifikasi User Non-Root pada Container ---"

WEB_UID=$(docker compose exec -T web id -u 2>/dev/null | tr -d '\r')
PHP_UID=$(docker compose exec -T php id -u 2>/dev/null | tr -d '\r')
NODE_UID=$(docker compose --profile tools exec node id -u 2>/dev/null | tr -d '\r')

if [ "$WEB_UID" != "0" ] && [ -n "$WEB_UID" ]; then
    pass "Container web berjalan sebagai non-root (UID=$WEB_UID)"
else
    fail "Container web berjalan sebagai root atau gagal (UID=$WEB_UID)"
fi

if [ "$PHP_UID" != "0" ] && [ -n "$PHP_UID" ]; then
    pass "Container php berjalan sebagai non-root (UID=$PHP_UID)"
else
    fail "Container php berjalan sebagai root atau gagal (UID=$PHP_UID)"
fi

if [ "$NODE_UID" != "0" ] && [ -n "$NODE_UID" ]; then
    pass "Container node berjalan sebagai non-root (UID=$NODE_UID)"
else
    fail "Container node berjalan sebagai root atau gagal (UID=$NODE_UID)"
fi

SCHEDULER_UID=$(docker compose exec -T scheduler id -u 2>/dev/null | tr -d '\r')
if [ "$SCHEDULER_UID" != "0" ] && [ -n "$SCHEDULER_UID" ]; then
    pass "Container scheduler berjalan sebagai non-root (UID=$SCHEDULER_UID)"
else
    fail "Container scheduler berjalan sebagai root atau gagal (UID=$SCHEDULER_UID)"
fi

# ------------------------------------------------------------------------------
# 2. docker inspect: ReadonlyRootfs=true, no-new-privileges, CapDrop ALL
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 2: Verifikasi ReadonlyRootfs, no-new-privileges, CapDrop ALL ---"

for svc in web php db node scheduler; do
    container_name="erp-trading-${svc}"
    ro=$(docker inspect "$container_name" --format '{{.HostConfig.ReadonlyRootfs}}' 2>/dev/null || true)
    nnp=$(docker inspect "$container_name" --format '{{json .HostConfig.SecurityOpt}}' 2>/dev/null || true)
    caps_drop=$(docker inspect "$container_name" --format '{{json .HostConfig.CapDrop}}' 2>/dev/null || true)
    caps_add=$(docker inspect "$container_name" --format '{{json .HostConfig.CapAdd}}' 2>/dev/null || true)

    if [ "$ro" = "true" ]; then
        pass "$container_name: ReadonlyRootfs = true"
    else
        fail "$container_name: ReadonlyRootfs is NOT true ($ro)"
    fi

    if [[ "$nnp" =~ no-new-privileges ]]; then
        pass "$container_name: no-new-privileges = true"
    else
        fail "$container_name: no-new-privileges is missing"
    fi

    if [[ "$caps_drop" =~ ALL ]]; then
        pass "$container_name: CapDrop = ALL (CapAdd: $caps_add)"
    else
        fail "$container_name: CapDrop ALL is missing ($caps_drop)"
    fi
done

# ------------------------------------------------------------------------------
# 3. Port terbuka di host: hanya 127.0.0.1 (web: 8080, node: 5173/5174); db tidak terpublikasi
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 3: Verifikasi Port Binding Host ---"

WEB_PORTS=$(docker inspect erp-trading-web --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}')
DB_PORTS=$(docker inspect erp-trading-db --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}')
NODE_PORTS=$(docker inspect erp-trading-node --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}')

if [[ "$WEB_PORTS" =~ ^127\.0\.0\.1:${APP_PORT:-8080}[[:space:]]*$ ]]; then
    pass "Port web hanya terikat ke 127.0.0.1:${APP_PORT:-8080} ($WEB_PORTS)"
else
    fail "Port web terikat tidak aman ($WEB_PORTS)"
fi

if [ -z "$DB_PORTS" ]; then
    pass "Port MariaDB TIDAK dipublikasikan ke host (DB_PORTS kosong)"
else
    fail "Port MariaDB terpublikasi ke host: $DB_PORTS"
fi

if [[ "$NODE_PORTS" =~ ^127\.0\.0\.1:${VITE_PORT:-5173}[[:space:]]*$ ]]; then
    pass "Port Vite node hanya terikat ke 127.0.0.1:${VITE_PORT:-5173} ($NODE_PORTS)"
else
    fail "Port Vite node terikat tidak aman ($NODE_PORTS)"
fi

# ------------------------------------------------------------------------------
# 4. Respons header tidak memuat X-Powered-By dan versi Nginx
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 4: Verifikasi Kebocoran Informasi pada Header HTTP ---"

HEADERS=$(curl -sI "http://127.0.0.1:${APP_PORT:-8080}/" || true)

if echo "$HEADERS" | grep -i "X-Powered-By" >/dev/null; then
    fail "Header memuat X-Powered-By: $(echo "$HEADERS" | grep -i "X-Powered-By")"
else
    pass "Header TIDAK memuat X-Powered-By"
fi

SERVER_HEADER=$(echo "$HEADERS" | grep -i "^server:" | tr -d '\r\n' || true)
if echo "$SERVER_HEADER" | grep -E "nginx/[0-9]" >/dev/null; then
    fail "Header Server membocorkan versi: $SERVER_HEADER"
else
    pass "Header Server terlindungi: $SERVER_HEADER (tanpa versi)"
fi

# ------------------------------------------------------------------------------
# 5. Uji Aturan Nginx dengan Berkas Nyata (Non-Destruktif)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 5: Verifikasi Pemblokiran Nginx dengan Berkas Nyata ---"

# Buat berkas-berkas pengujian di src/public
safe_create_file "src/public/.env" "SECRET_KEY=leak"
safe_create_file "src/public/composer.json" '{"name": "test"}'
safe_create_dir "src/public/vendor"
safe_create_file "src/public/vendor/x_${PROBE_ID}.txt" "sensitive"
safe_create_dir "src/public/storage"
safe_create_file "src/public/storage/x_${PROBE_ID}.txt" "sensitive"
safe_create_dir "src/public/uploads"
safe_create_file "src/public/uploads/x_${PROBE_ID}.php" "<?php echo 'shell';"
safe_create_dir "src/public/sub"
safe_create_file "src/public/sub/shell_${PROBE_ID}.php" "<?php echo 'shell';"

# Kontrol positif: letakkan probe di build/assets jika sudah ada, atau buat jika belum
safe_create_dir "src/public/build"
safe_create_dir "src/public/build/assets"
safe_create_file "src/public/build/assets/vendor-test-${PROBE_ID}.js" "console.log('vendor-test');"
safe_create_file "src/public/ok_${PROBE_ID}.txt" "all good"

check_http_code() {
    local path="$1"
    local expected="$2"
    local desc="$3"
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}${path}")
    if [[ "$code" =~ $expected ]]; then
        pass "[$code] $path: $desc"
    else
        fail "[$code] $path: $desc (harapan: $expected)"
    fi
}

check_http_code "/.env" "^(403|404)$" "Berkas sensitif .env diblokir"
check_http_code "/composer.json" "^(403|404)$" "Berkas composer.json diblokir"
check_http_code "/vendor/x_${PROBE_ID}.txt" "^(403|404)$" "Direktori vendor diblokir"
check_http_code "/storage/x_${PROBE_ID}.txt" "^(403|404)$" "Direktori storage diblokir"
check_http_code "/uploads/x_${PROBE_ID}.php" "^(403|404)$" "Direktori uploads diblokir"
check_http_code "/sub/shell_${PROBE_ID}.php" "^404$" "Skrip PHP selain /index.php diblokir (404)"

# Uji Kontrol Positif
check_http_code "/build/assets/vendor-test-${PROBE_ID}.js" "^200$" "Aset valid build/assets/vendor-*.js dapat diakses (200)"
check_http_code "/ok_${PROBE_ID}.txt" "^200$" "Berkas statis publik normal dapat diakses (200)"

# ------------------------------------------------------------------------------
# 6. Probe PHP-FPM Independen via cgi-fcgi & Verifikasi Ekstensi (F2)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 6: Verifikasi PHP Hardening (FPM via cgi-fcgi & CLI) ---"

# Buat berkas probe di tmpfs /tmp container php
docker compose exec -T php sh -c "cat << 'EOF' > $PROBE_FPM_FILE
<?php
echo json_encode([
    'disable_functions' => ini_get('disable_functions'),
    'exec_exists' => function_exists('exec'),
    'proc_open_exists' => function_exists('proc_open'),
    'open_basedir' => ini_get('open_basedir'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
    'expose_php' => ini_get('expose_php'),
    'extensions' => get_loaded_extensions(),
]);
EOF"

FPM_RAW=$(docker compose exec -T php sh -c "SCRIPT_FILENAME=$PROBE_FPM_FILE REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000" 2>/dev/null || true)
docker compose exec -T php rm -f "$PROBE_FPM_FILE" 2>/dev/null || true

FPM_JSON=$(echo "$FPM_RAW" | tr -d '\r' | grep -m 1 '^{' || true)

# Parse disable_functions secara spesifik dari JSON (bukan grep exec yang bisa cocok ke key exec_exists)
FPM_DISABLE_FUNCS=$(docker compose exec -T php php -r '
    $raw = @file_get_contents("php://stdin");
    $data = json_decode($raw, true);
    echo $data["disable_functions"] ?? "";
' <<< "$FPM_JSON" 2>/dev/null || true)

for fn in exec passthru shell_exec system proc_open popen pcntl_exec; do
    if echo ",$FPM_DISABLE_FUNCS," | grep -q ",$fn,"; then
        pass "FPM disable_functions memuat '$fn'"
    else
        fail "FPM disable_functions TIDAK memuat '$fn' ($FPM_DISABLE_FUNCS)"
    fi
done

if echo "$FPM_JSON" | grep -q '"exec_exists":false'; then
    pass "function_exists('exec') pada FPM bernilai false"
else
    fail "function_exists('exec') pada FPM TIDAK bernilai false ($FPM_JSON)"
fi

if echo "$FPM_JSON" | grep -q '"proc_open_exists":false'; then
    pass "function_exists('proc_open') pada FPM bernilai false"
else
    fail "function_exists('proc_open') pada FPM TIDAK bernilai false ($FPM_JSON)"
fi

if echo "$FPM_JSON" | tr -d '\\' | grep -q '/var/www/html'; then
    pass "open_basedir pada FPM terisolasi pada /var/www/html:/tmp"
else
    fail "open_basedir pada FPM tidak sesuai ($FPM_JSON)"
fi

if echo "$FPM_JSON" | grep -E '"allow_url_fopen":(""|"0"|false)' >/dev/null; then
    pass "allow_url_fopen pada FPM berstatus Off"
else
    fail "allow_url_fopen pada FPM belum Off ($FPM_JSON)"
fi

if echo "$FPM_JSON" | grep -E '"expose_php":(""|"0"|false)' >/dev/null; then
    pass "expose_php pada FPM berstatus Off"
else
    fail "expose_php pada FPM belum Off ($FPM_JSON)"
fi

# Verifikasi ekstensi wajib ADA pada FPM dan CLI
FPM_EXTS=$(docker compose exec -T php php -r '
    $raw = @file_get_contents("php://stdin");
    $data = json_decode($raw, true);
    echo implode(",", $data["extensions"] ?? []);
' <<< "$FPM_JSON" 2>/dev/null || true)

CLI_EXTS=$(docker compose exec -T php php -r 'echo implode(",", get_loaded_extensions());' 2>/dev/null || true)

for ext in pdo_mysql intl bcmath zip "Zend OPcache"; do
    if echo ",$FPM_EXTS," | grep -qi ",$ext,"; then
        pass "Ekstensi wajib '$ext' aktif pada FPM"
    else
        fail "Ekstensi wajib '$ext' TIDAK aktif pada FPM"
    fi

    if echo ",$CLI_EXTS," | grep -qi ",$ext,"; then
        pass "Ekstensi wajib '$ext' aktif pada CLI"
    else
        fail "Ekstensi wajib '$ext' TIDAK aktif pada CLI"
    fi
done

# Verifikasi ekstensi berbahaya pcntl TIDAK ADA pada FPM maupun CLI
if echo ",$FPM_EXTS," | grep -qi ",pcntl,"; then
    fail "Ekstensi terlarang pcntl TERPASANG pada FPM!"
else
    pass "Ekstensi terlarang pcntl TIDAK ADA pada FPM"
fi

if echo ",$CLI_EXTS," | grep -qi ",pcntl,"; then
    fail "Ekstensi terlarang pcntl TERPASANG pada CLI!"
else
    pass "Ekstensi terlarang pcntl TIDAK ADA pada CLI"
fi

# Cek ketersediaan proc_open di CLI
CLI_PROC_OPEN=$(docker compose exec -T php php -r "echo function_exists('proc_open') ? 'yes' : 'no';" 2>/dev/null | tr -d '\r')
if [ "$CLI_PROC_OPEN" = "yes" ]; then
    pass "proc_open TERSEDIA pada PHP CLI untuk kebutuhan Composer & Artisan"
else
    fail "proc_open TIDAK tersedia pada PHP CLI ($CLI_PROC_OPEN)"
fi

# ------------------------------------------------------------------------------
# 7. Verifikasi Composer, NPM, & Cache Writability pada Read-Only Rootfs
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 7: Verifikasi Composer CLI, NPM, & Cache Writability ---"

COMPOSER_VER=$(docker compose exec -T php composer --version 2>/dev/null | tr -d '\r')
if [[ "$COMPOSER_VER" =~ Composer[[:space:]]version ]]; then
    pass "composer --version berjalan sukses di container php: $COMPOSER_VER"
else
    fail "composer --version gagal dijalankan ($COMPOSER_VER)"
fi

# Uji writability direktori cache composer
if docker compose run --rm php touch "$PROBE_COMPOSER_WRITE" 2>/dev/null; then
    pass "Cache COMPOSER_HOME (/tmp/composer) dapat ditulis (writable) pada rootfs read-only"
else
    fail "Cache COMPOSER_HOME TIDAK dapat ditulis pada rootfs read-only"
fi

# Uji composer diagnose tanpa permission error
if docker compose run --rm php composer diagnose 2>&1 | grep -iq "fail\|error"; then
    fail "composer diagnose mendeteksi error permission/konfigurasi"
else
    pass "composer diagnose lolos tanpa error permission"
fi

# Uji writability direktori cache npm
if docker compose --profile tools run --rm node touch "$PROBE_NPM_WRITE" 2>/dev/null; then
    pass "Cache NPM (~/.npm) dapat ditulis (writable) pada rootfs read-only"
else
    fail "Cache NPM TIDAK dapat ditulis pada rootfs read-only"
fi

# ------------------------------------------------------------------------------
# 8. Verifikasi Hardening Database MariaDB (F4: Tanpa password di argumen CLI)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 8: Verifikasi Hardening Database MariaDB ---"

# Versi 12.3.x
DB_VERSION=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT VERSION();" 2>/dev/null | tr -d '\r')
if [[ "$DB_VERSION" =~ ^12\.3\. ]]; then
    pass "MariaDB versi 12.3.x ($DB_VERSION)"
else
    fail "MariaDB BUKAN versi 12.3.x ($DB_VERSION)"
fi

# local_infile=0
LOCAL_INFILE=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT @@local_infile;" 2>/dev/null | tr -d '\r')
if [ "$LOCAL_INFILE" = "0" ]; then
    pass "local_infile = 0"
else
    fail "local_infile bukan 0 ($LOCAL_INFILE)"
fi

# mariadbd berjalan sebagai dedicated user 'mysql'
MARIADB_USER=$(docker compose exec -T db ps aux | grep -E '[m]ariadbd|[m]ysqld' | awk '{print $1}' | head -n 1 | tr -d '\r')
if [ "$MARIADB_USER" = "mysql" ]; then
    pass "mariadbd berjalan sebagai dedicated user 'mysql' ($MARIADB_USER)"
else
    fail "mariadbd TIDAK berjalan sebagai user mysql ($MARIADB_USER)"
fi

# User anonim tidak ada
ANON_USERS=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT COUNT(*) FROM mysql.user WHERE User = '';" 2>/dev/null | tr -d '\r')
if [ "$ANON_USERS" = "0" ]; then
    pass "Tidak ada user anonim pada MariaDB"
else
    fail "Ditemukan $ANON_USERS user anonim"
fi

# User root HANYA diizinkan dari localhost/127.0.0.1/::1
ROOT_HOSTS=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT host FROM mysql.user WHERE user='root';" 2>/dev/null | tr '\n' ' ' | tr -d '\r')
ROOT_HOSTS_TRIM=$(echo "$ROOT_HOSTS" | xargs)
if [[ "$ROOT_HOSTS" =~ ^(localhost|127\.0\.0\.1|::1|[[:space:]])+$ ]] && [ -n "$ROOT_HOSTS_TRIM" ]; then
    pass "User root MariaDB dibatasi HANYA pada host lokal ($ROOT_HOSTS_TRIM)"
else
    fail "User root MariaDB memiliki host yang tidak aman ($ROOT_HOSTS_TRIM)"
fi

# erp_app TIDAK bisa CREATE TABLE (F4: via MYSQL_PWD)
APP_CREATE_RES=$(docker compose exec -T -e MYSQL_PWD="${DB_APP_PASSWORD}" db mariadb -u "${DB_APP_USERNAME}" -e "CREATE TABLE erp_trading.probe_app (id INT);" 2>&1 || true)
if echo "$APP_CREATE_RES" | grep -Eiq "denied|access denied"; then
    pass "erp_app DITOLAK saat melakukan CREATE TABLE (Command denied to user)"
else
    fail "erp_app SEHARUSNYA TIDAK BISA CREATE TABLE ($APP_CREATE_RES)"
fi

# erp_migrator bisa DDL & DML (F4: via MYSQL_PWD)
if docker compose exec -T -e MYSQL_PWD="${DB_MIGRATOR_PASSWORD}" db mariadb -u "${DB_MIGRATOR_USERNAME}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
    CREATE TABLE erp_trading.probe_migrator (id INT PRIMARY KEY, name VARCHAR(50));
" >/dev/null 2>&1; then
    pass "erp_migrator BERHASIL membuat tabel migrasi (DDL)"
else
    fail "erp_migrator GAGAL membuat tabel migrasi (DDL)"
fi

# erp_app bisa DML pada tabel tersebut (F4: via MYSQL_PWD)
if docker compose exec -T -e MYSQL_PWD="${DB_APP_PASSWORD}" db mariadb -u "${DB_APP_USERNAME}" -e "
    INSERT INTO erp_trading.probe_migrator VALUES (1, 'test');
    UPDATE erp_trading.probe_migrator SET name = 'updated' WHERE id = 1;
    SELECT * FROM erp_trading.probe_migrator;
    DELETE FROM erp_trading.probe_migrator WHERE id = 1;
" >/dev/null 2>&1; then
    pass "erp_app BERHASIL melakukan operasi DML (SELECT, INSERT, UPDATE, DELETE)"
else
    fail "erp_app GAGAL melakukan operasi DML pada tabel migrasi"
fi

# Cleanup probe table (F4: via MYSQL_PWD)
docker compose exec -T -e MYSQL_PWD="${DB_MIGRATOR_PASSWORD}" db mariadb -u "${DB_MIGRATOR_USERNAME}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
" >/dev/null 2>&1 || true

# erp_app tidak punya FILE privilege dan hanya DML
GRANTS_APP=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SHOW GRANTS FOR '${DB_APP_USERNAME}'@'%';" 2>/dev/null || true)
if echo "$GRANTS_APP" | grep -qiE "FILE|SUPER|PROCESS|GRANT OPTION|TRIGGER|CREATE|DROP|ALTER"; then
    fail "erp_app memiliki privilege terlarang (harus strictly DML)"
else
    pass "erp_app strictly DML dan TIDAK memiliki privilege FILE, SUPER, PROCESS, TRIGGER, atau DDL"
fi

# erp_migrator memiliki TRIGGER dan tidak memiliki SUPER, FILE, PROCESS, GRANT OPTION
GRANTS_MIGRATOR=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SHOW GRANTS FOR '${DB_MIGRATOR_USERNAME}'@'%';" 2>/dev/null || true)
if echo "$GRANTS_MIGRATOR" | grep -qi "TRIGGER"; then
    pass "erp_migrator memiliki hak TRIGGER pada database"
else
    fail "erp_migrator TIDAK memiliki hak TRIGGER"
fi

if echo "$GRANTS_MIGRATOR" | grep -qiE "SUPER|FILE|PROCESS|GRANT OPTION"; then
    fail "erp_migrator memiliki privilege terlarang (SUPER/FILE/PROCESS/GRANT OPTION)"
else
    pass "erp_migrator TIDAK memuat privilege SUPER, FILE, PROCESS, atau GRANT OPTION"
fi

# Isolasi network: db tidak terhubung ke frontend
DB_NETWORKS=$(docker inspect erp-trading-db --format '{{json .NetworkSettings.Networks}}')
if echo "$DB_NETWORKS" | grep -q "frontend"; then
    fail "db TERHUBUNG ke jaringan frontend (seharusnya hanya backend)"
else
    pass "db terisolasi di jaringan backend internal (tidak terhubung ke frontend)"
fi

# ------------------------------------------------------------------------------
# 9. Verifikasi Kebocoran Rahasia & Isolasi Container PHP (F3)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 9: Verifikasi Kebocoran Rahasia & Isolasi Direktori PHP ---"

# Periksa apakah password root muncul di docker inspect
LEAK_INSPECT=false
for svc in web php db node scheduler; do
    container_name="erp-trading-${svc}"
    if docker inspect "$container_name" | grep -q "$DB_ROOT_SECRET"; then
        fail "String password root MariaDB BOCOR di docker inspect $container_name!"
        LEAK_INSPECT=true
    fi
done
if [ "$LEAK_INSPECT" = "false" ]; then
    pass "Password root MariaDB TIDAK bocor di docker inspect layanan mana pun"
fi

# Periksa apakah password root muncul di env container php
PHP_ENV=$(docker compose exec -T php env 2>/dev/null || true)
if echo "$PHP_ENV" | grep -q "$DB_ROOT_SECRET"; then
    fail "String password root MariaDB BOCOR di environment container php!"
else
    pass "Password root MariaDB TIDAK bocor di environment container php"
fi

# Periksa apakah kredensial erp_migrator bocor di environment container php (F3)
if echo "$PHP_ENV" | grep -Eiq "${DB_MIGRATOR_USERNAME}|${DB_MIGRATOR_PASSWORD}"; then
    fail "Kredensial erp_migrator BOCOR di environment container php yang berjalan!"
else
    pass "Environment container php bersih dari kredensial erp_migrator"
fi

# Container PHP TIDAK boleh melihat berkas infra: .git, secrets/, docker/, compose.yaml
ISOLATED=true
for forbidden in .git secrets docker compose.yaml compose.yml; do
    if docker compose exec -T php test -e "/var/www/html/${forbidden}"; then
        fail "Container php DAPAT melihat berkas infra '${forbidden}' di /var/www/html"
        ISOLATED=false
    fi
done
if [ "$ISOLATED" = "true" ]; then
    pass "Container php terisolasi dari berkas infra (.git, secrets, docker, compose.yaml)"
fi

# Jika src/.env milik Laravel ada, verifikasi isinya TIDAK memuat root password atau kredensial erp_migrator (F3)
if [ -f "src/.env" ]; then
    if grep -q "$DB_ROOT_SECRET" src/.env; then
        fail "src/.env MEMUAT password root MariaDB!"
    elif grep -Eiq "${DB_MIGRATOR_USERNAME}|${DB_MIGRATOR_PASSWORD}" src/.env; then
        fail "src/.env MEMUAT kredensial erp_migrator!"
    else
        pass "src/.env milik Laravel terverifikasi bersih dari password root dan kredensial erp_migrator"
    fi
else
    pass "src/.env belum ada pada tahap pra-instalasi Laravel (bersih)"
fi

# ------------------------------------------------------------------------------
# 10. Verifikasi Hak Akses Direktori secrets (F5)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 10: Verifikasi Hak Akses Direktori secrets ---"

SECRETS_PERM=$(stat -c '%a' secrets 2>/dev/null || stat -f '%A' secrets 2>/dev/null || true)
if [ "$SECRETS_PERM" = "700" ]; then
    pass "Direktori secrets/ memiliki hak akses ketat 700 ($SECRETS_PERM)"
else
    fail "Direktori secrets/ TIDAK memiliki mode 700 ($SECRETS_PERM)"
fi

# ------------------------------------------------------------------------------
# 11. Uji Fungsional Git Hooks (F6)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 11: Uji Fungsional Git Hooks ---"

if bash scripts/test-hooks.sh >/dev/null 2>&1; then
    pass "Seluruh uji fungsional Git hooks (pre-commit & commit-msg) berhasil"
else
    fail "Uji fungsional Git hooks GAGAL"
fi

# ------------------------------------------------------------------------------
# 12. Gitleaks lolos pada seluruh repo (F5: :ro dan --network none)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 12: Verifikasi Pemindaian Rahasia Gitleaks ---"

GITLEAKS_IMG="zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"
if docker run --rm --network none -v "$(pwd):/repo:ro" -w /repo "$GITLEAKS_IMG" git --verbose --no-banner >/dev/null 2>&1; then
    pass "Gitleaks lolos tanpa temuan rahasia pada seluruh repositori"
else
    fail "Gitleaks mendeteksi rahasia pada repositori"
fi

# ------------------------------------------------------------------------------
# 13. git ls-files tidak memuat .env atau key
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 13: Verifikasi File Rahasia di Git Index ---"

FORBIDDEN_FILES=$(git ls-files | grep -Ei '\.env|\.pem|\.key|\.crt|id_rsa|id_ed25519' | grep -v '\.env\.example' | grep -v '\.env\.production\.example' || true)
if [ -z "$FORBIDDEN_FILES" ]; then
    pass "git ls-files bersih dari .env atau key privat"
else
    fail "Ditemukan file sensitif terindeks di Git: $FORBIDDEN_FILES"
fi

# ------------------------------------------------------------------------------
# 14. Verifikasi Endpoint & Baseline Keamanan Aplikasi Laravel
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 14: Verifikasi Endpoint & Header Keamanan Aplikasi Laravel ---"

LOGIN_RES_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/login)
if [ "$LOGIN_RES_CODE" = "200" ]; then
    pass "Endpoint GET /login mengembalikan HTTP 200"
else
    fail "Endpoint GET /login mengembalikan HTTP $LOGIN_RES_CODE (diharapkan 200)"
fi

LOGIN_BODY=$(curl -s http://127.0.0.1:8080/login)
if echo "$LOGIN_BODY" | grep -q 'data-page' || echo "$LOGIN_BODY" | grep -q 'ERP Distribusi'; then
    pass "Endpoint GET /login memuat komponen aplikasi Inertia ('data-page' / 'ERP Distribusi')"
else
    fail "Endpoint GET /login TIDAK memuat komponen Inertia"
fi

UP_RES_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/up)
if [ "$UP_RES_CODE" = "200" ]; then
    pass "Endpoint healthcheck GET /up mengembalikan HTTP 200"
else
    fail "Endpoint healthcheck GET /up mengembalikan HTTP $UP_RES_CODE (diharapkan 200)"
fi

LOGIN_HEADERS=$(curl -s -I http://127.0.0.1:8080/login)

if echo "$LOGIN_HEADERS" | grep -qi "Content-Security-Policy" && echo "$LOGIN_HEADERS" | grep -qi "nonce-"; then
    pass "Header Content-Security-Policy hadir dan memuat per-request nonce"
else
    fail "Header Content-Security-Policy TIDAK hadir atau tidak memuat nonce"
fi

if echo "$LOGIN_HEADERS" | grep -i "Content-Security-Policy" | grep -qE "style-src[^;]*'unsafe-inline'[^;]*'nonce-|style-src[^;]*'nonce-[^;]*'unsafe-inline'"; then
    fail "Direktif style-src mencampur 'unsafe-inline' dengan nonce (menyebabkan browser mengabaikan unsafe-inline)!"
else
    pass "Direktif style-src tidak mencampur 'unsafe-inline' dengan nonce pada direktif yang sama"
fi

if echo "$LOGIN_HEADERS" | grep -qi "X-Content-Type-Options:[[:space:]]*nosniff"; then
    pass "Header X-Content-Type-Options: nosniff terpasang dengan benar"
else
    fail "Header X-Content-Type-Options: nosniff TIDAK ditemukan"
fi

if echo "$LOGIN_HEADERS" | grep -qi "X-Frame-Options:[[:space:]]*DENY"; then
    pass "Header X-Frame-Options: DENY terpasang dengan benar"
else
    fail "Header X-Frame-Options: DENY TIDAK ditemukan"
fi

if echo "$LOGIN_HEADERS" | grep -qi "Referrer-Policy:[[:space:]]*strict-origin-when-cross-origin"; then
    pass "Header Referrer-Policy: strict-origin-when-cross-origin terpasang dengan benar"
else
    fail "Header Referrer-Policy: strict-origin-when-cross-origin TIDAK ditemukan"
fi

if echo "$LOGIN_HEADERS" | grep -qi "Permissions-Policy:"; then
    pass "Header Permissions-Policy terpasang dengan benar pada respons GET /login"
else
    fail "Header Permissions-Policy TIDAK ditemukan pada respons GET /login"
fi

if echo "$LOGIN_HEADERS" | grep -qi "Cross-Origin-Opener-Policy:[[:space:]]*same-origin"; then
    pass "Header Cross-Origin-Opener-Policy: same-origin terpasang dengan benar"
else
    fail "Header Cross-Origin-Opener-Policy: same-origin TIDAK ditemukan"
fi

if echo "$LOGIN_HEADERS" | grep -qi "Strict-Transport-Security"; then
    fail "Header Strict-Transport-Security TIDAK boleh hadir pada lingkungan APP_ENV=local"
else
    pass "Header Strict-Transport-Security tidak hadir pada lingkungan APP_ENV=local (sesuai spesifikasi)"
fi

NOT_FOUND_RESP=$(curl -s -i http://127.0.0.1:8080/halaman-tidak-ada)
NOT_FOUND_CODE=$(echo "$NOT_FOUND_RESP" | head -n 1 | awk '{print $2}')
if [ "$NOT_FOUND_CODE" = "404" ]; then
    pass "Endpoint URL tidak terdaftar mengembalikan status HTTP 404"
else
    fail "Endpoint URL tidak terdaftar TIDAK mengembalikan status HTTP 404 (HTTP $NOT_FOUND_CODE)"
fi

if echo "$NOT_FOUND_RESP" | grep -qiE "Stack trace|/var/www/html"; then
    fail "Halaman 404 membocorkan Stack trace atau path internal sistem (/var/www/html)!"
else
    pass "Halaman 404 aman dan tidak membocorkan Stack trace atau path internal"
fi

# Header keamanan pada respons 404 (F1 & F2)
NOT_FOUND_HEADERS=$(curl -s -I http://127.0.0.1:8080/halaman-tidak-ada)
if echo "$NOT_FOUND_HEADERS" | grep -qi "Content-Security-Policy" && \
   echo "$NOT_FOUND_HEADERS" | grep -qi "X-Content-Type-Options:[[:space:]]*nosniff" && \
   echo "$NOT_FOUND_HEADERS" | grep -qi "X-Frame-Options:[[:space:]]*DENY" && \
   echo "$NOT_FOUND_HEADERS" | grep -qi "Referrer-Policy:[[:space:]]*strict-origin-when-cross-origin" && \
   echo "$NOT_FOUND_HEADERS" | grep -qi "Permissions-Policy:" && \
   echo "$NOT_FOUND_HEADERS" | grep -qi "Cross-Origin-Opener-Policy:[[:space:]]*same-origin"; then
    pass "Header keamanan lengkap hadir pada respons 404 (CSP, nosniff, DENY, Referrer, Permissions, COOP)"
else
    fail "Respons 404 TIDAK memuat seluruh header keamanan global wajib"
fi

if echo "$NOT_FOUND_RESP" | grep -qiE "<style|style="; then
    fail "Halaman 404 memuat tag <style> atau atribut style= inline!"
else
    pass "Halaman 404 bebas dari tag <style> dan atribut style= inline (menggunakan static error.css)"
fi

ABOUT_PROD_OUTPUT=$(docker compose run --rm -e APP_ENV=production php php artisan about --only=environment 2>&1)
if echo "$ABOUT_PROD_OUTPUT" | grep -i "Debug Mode" | grep -qi "OFF"; then
    pass "php artisan about mengonfirmasi Debug Mode OFF saat dijalankan dengan APP_ENV=production sementara"
else
    fail "php artisan about TIDAK menonaktifkan Debug Mode saat APP_ENV=production!"
fi

if echo "$LOGIN_HEADERS" | grep -i "Set-Cookie" | grep -qi "HttpOnly"; then
    pass "Cookie sesi Laravel mengonfigurasi atribut HttpOnly"
else
    fail "Cookie sesi Laravel TIDAK memuat atribut HttpOnly"
fi

if echo "$LOGIN_HEADERS" | grep -i "Set-Cookie" | grep -qi "SameSite=lax"; then
    pass "Cookie sesi Laravel mengonfigurasi atribut SameSite=lax"
else
    fail "Cookie sesi Laravel TIDAK memuat atribut SameSite=lax"
fi

STORAGE_LOG_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/storage/logs/laravel.log)
if [ "$STORAGE_LOG_CODE" = "404" ] || [ "$STORAGE_LOG_CODE" = "403" ]; then
    pass "Akses HTTP langsung ke /storage/logs/laravel.log diblokir Nginx (HTTP $STORAGE_LOG_CODE)"
else
    fail "Akses ke /storage/logs/laravel.log TIDAK diblokir (HTTP $STORAGE_LOG_CODE)"
fi

ENV_REQ_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/.env)
if [ "$ENV_REQ_CODE" = "404" ] || [ "$ENV_REQ_CODE" = "403" ]; then
    pass "Akses HTTP langsung ke /.env diblokir Nginx (HTTP $ENV_REQ_CODE)"
else
    fail "Akses ke /.env TIDAK diblokir (HTTP $ENV_REQ_CODE)"
fi

if grep -q "http://127.0.0.1:8080/up" compose.yaml; then
    pass "Healthcheck service web di compose.yaml menggunakan endpoint /up"
else
    fail "Healthcheck service web di compose.yaml TIDAK menggunakan endpoint /up"
fi

if [ -d "src/public/build" ]; then
    pass "Direktori aset frontend src/public/build tetap utuh dan tidak terhapus probe"
else
    fail "Direktori src/public/build terhapus secara destruktif!"
fi

# ------------------------------------------------------------------------------
# 15. Verifikasi Skema Domain & Trigger Integritas (Fase 2)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 15: Verifikasi Skema Domain & Trigger Integritas (Fase 2) ---"

# 1. Jumlah tabel domain = 37
DOMAIN_TABLES_COUNT=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'erp_trading'
      AND TABLE_TYPE = 'BASE TABLE'
      AND TABLE_NAME NOT IN ('migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'probe_migrator');
" 2>/dev/null | tr -d '[:space:]')

if [ "$DOMAIN_TABLES_COUNT" = "37" ]; then
    pass "Jumlah tabel domain ERP Distribusi/Trading tepat 37 tabel (ditemukan $DOMAIN_TABLES_COUNT)"
else
    fail "Jumlah tabel domain TIDAK tepat 37 tabel (ditemukan $DOMAIN_TABLES_COUNT)!"
fi

# 2. Distribusi aturan FK
FK_RULES=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT DELETE_RULE, COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'erp_trading'
    GROUP BY DELETE_RULE
    ORDER BY DELETE_RULE;
" 2>/dev/null)

CASCADE_COUNT=$(echo "$FK_RULES" | awk '$1=="CASCADE" {print $2}')
RESTRICT_COUNT=$(echo "$FK_RULES" | awk '$1=="RESTRICT" {print $2}')
OTHER_FK_RULES=$(echo "$FK_RULES" | grep -vE "^(CASCADE|RESTRICT)" || true)

NON_PIVOT_CASCADE=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT CONSTRAINT_NAME
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'erp_trading'
      AND DELETE_RULE = 'CASCADE'
      AND TABLE_NAME NOT IN ('role_user', 'permission_role');
" 2>/dev/null | tr -d '[:space:]')

if [ "$CASCADE_COUNT" = "4" ] && [ -z "$NON_PIVOT_CASCADE" ] && [ -n "$RESTRICT_COUNT" ] && [ "$RESTRICT_COUNT" -gt 0 ] && [ -z "$OTHER_FK_RULES" ]; then
    pass "Distribusi Foreign Key valid: CASCADE=4 (2 di role_user, 2 di permission_role), RESTRICT=$RESTRICT_COUNT, tanpa rule lain"
else
    fail "Distribusi aturan Foreign Key TIDAK valid! (CASCADE: $CASCADE_COUNT, RESTRICT: $RESTRICT_COUNT, Non-pivot CASCADE: $NON_PIVOT_CASCADE, Other: $OTHER_FK_RULES)"
fi

# 3. Keberadaan trigger pada audit_logs dan stock_movements
TRIGGERS_OUTPUT=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'erp_trading';
" 2>/dev/null)

if echo "$TRIGGERS_OUTPUT" | grep -q "tr_audit_logs_before_update" && \
   echo "$TRIGGERS_OUTPUT" | grep -q "tr_audit_logs_before_delete" && \
   echo "$TRIGGERS_OUTPUT" | grep -q "tr_stock_movements_before_update" && \
   echo "$TRIGGERS_OUTPUT" | grep -q "tr_stock_movements_before_delete"; then
    pass "Trigger integritas append-only (BEFORE UPDATE & BEFORE DELETE) hadir pada audit_logs dan stock_movements"
else
    fail "Trigger integritas append-only TIDAK lengkap pada audit_logs dan stock_movements!"
fi

# ------------------------------------------------------------------------------
# 16. Verifikasi Autentikasi, Sesi, dan Pembatasan Laju (Fase 3)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 16: Verifikasi Autentikasi, Sesi, dan Pembatasan Laju (Fase 3) ---"

# 1. GET / tanpa login = 302 ke /login
AUTH_ROOT_RESP=$(curl -s -i http://127.0.0.1:8080/)
AUTH_ROOT_CODE=$(echo "$AUTH_ROOT_RESP" | head -n 1 | awk '{print $2}')
AUTH_ROOT_LOC=$(echo "$AUTH_ROOT_RESP" | grep -i "^Location:" | tr -d '\r' | awk '{print $2}')

if [ "$AUTH_ROOT_CODE" = "302" ] && echo "$AUTH_ROOT_LOC" | grep -q "/login"; then
    pass "Endpoint GET / tanpa login mengembalikan HTTP 302 dan mengarahkan ke /login ($AUTH_ROOT_LOC)"
else
    fail "Endpoint GET / tanpa login TIDAK mengembalikan 302 ke /login (Code: $AUTH_ROOT_CODE, Location: $AUTH_ROOT_LOC)"
fi

# 2. GET /login = 200 dengan CSP bernonce dan header keamanan lengkap
AUTH_LOGIN_RESP=$(curl -s -I http://127.0.0.1:8080/login)
AUTH_LOGIN_CODE=$(echo "$AUTH_LOGIN_RESP" | head -n 1 | awk '{print $2}')
if [ "$AUTH_LOGIN_CODE" = "200" ] && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "Content-Security-Policy" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "nonce-" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "X-Content-Type-Options:[[:space:]]*nosniff" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "X-Frame-Options:[[:space:]]*DENY" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "Referrer-Policy:[[:space:]]*strict-origin-when-cross-origin" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "Permissions-Policy:" && \
   echo "$AUTH_LOGIN_RESP" | grep -qi "Cross-Origin-Opener-Policy:[[:space:]]*same-origin"; then
    pass "Endpoint GET /login mengembalikan HTTP 200 dengan CSP bernonce dan header keamanan lengkap"
else
    fail "Endpoint GET /login TIDAK mengembalikan HTTP 200 dengan CSP bernonce dan header lengkap"
fi

# 3. POST /login tanpa CSRF = 419
NO_CSRF_CODE=$(curl -s -o /dev/null -w "%{http_code}" -d "email=probe@example.com&password=wrongpassword" http://127.0.0.1:8080/login)
if [ "$NO_CSRF_CODE" = "419" ]; then
    pass "POST /login tanpa token CSRF ditolak dengan HTTP 419"
else
    fail "POST /login tanpa token CSRF TIDAK ditolak dengan HTTP 419 (mengembalikan HTTP $NO_CSRF_CODE)"
fi

# 4. Cookie sesi HttpOnly + SameSite=Lax
COOKIE_RESP=$(curl -s -I http://127.0.0.1:8080/login)
SESSION_COOKIE=$(echo "$COOKIE_RESP" | grep -i "Set-Cookie:" | grep -i "erp-distribusi-session" || true)

if echo "$SESSION_COOKIE" | grep -qi "HttpOnly" && echo "$SESSION_COOKIE" | grep -qi "SameSite=Lax"; then
    pass "Cookie sesi erp-distribusi-session memiliki atribut HttpOnly dan SameSite=Lax"
else
    fail "Cookie sesi erp-distribusi-session TIDAK memiliki HttpOnly atau SameSite=Lax ($SESSION_COOKIE)"
fi

# 5. 6 percobaan login gagal beruntun menghasilkan 429
COOKIE_JAR=$(mktemp)
RANDOM_FAIL_EMAIL="verify_probe_$(date +%s)_$RANDOM@example.com"
curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" http://127.0.0.1:8080/login >/dev/null

LAST_HTTP_CODE=""
for i in {1..6}; do
    CSRF_COOKIE_VAL=$(grep "XSRF-TOKEN" "$COOKIE_JAR" | awk '{print $NF}' || true)
    XSRF_DECODED=$(printf '%b' "${CSRF_COOKIE_VAL//%/\\x}")
    LAST_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
        -H "X-XSRF-TOKEN: $XSRF_DECODED" \
        -H "Accept: application/json" \
        -d "email=${RANDOM_FAIL_EMAIL}&password=invalid_password_attempt" \
        http://127.0.0.1:8080/login)
done
rm -f "$COOKIE_JAR"

if [ "$LAST_HTTP_CODE" = "429" ]; then
    pass "Percobaan login gagal beruntun (ke-6) dibatasi laju dan mengembalikan HTTP 429"
else
    fail "Percobaan login gagal beruntun ke-6 TIDAK mengembalikan HTTP 429 (mengembalikan HTTP $LAST_HTTP_CODE)"
fi

# 6. password_algos() memuat argon2id pada FPM dan CLI
CLI_ARGON=$(docker compose exec -T php php -r 'echo in_array("argon2id", password_algos()) ? "OK" : "MISSING";' 2>/dev/null || true)

PROBE_AUTH_FILE="/tmp/probe_auth_$$.php"
docker compose exec -T php sh -c "cat << 'EOF' > $PROBE_AUTH_FILE
<?php
echo in_array('argon2id', password_algos()) ? 'OK' : 'MISSING';
EOF"
FPM_ARGON=$(docker compose exec -T php sh -c "SCRIPT_FILENAME=$PROBE_AUTH_FILE REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000" 2>/dev/null || true)
docker compose exec -T php rm -f "$PROBE_AUTH_FILE" 2>/dev/null || true

if [ "$CLI_ARGON" = "OK" ] && echo "$FPM_ARGON" | grep -q "OK"; then
    pass "password_algos() memuat argon2id pada PHP CLI dan PHP-FPM"
else
    fail "password_algos() TIDAK memuat argon2id pada CLI ($CLI_ARGON) atau FPM ($FPM_ARGON)"
fi

# 7. Tidak ada user dengan email default yang dikenal di database erp_trading
DEFAULT_USERS_COUNT=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT COUNT(*) FROM erp_trading.users
    WHERE email IN ('admin@example.com', 'admin@erp.local', 'admin@erp.com', 'root@localhost', 'user@example.com', 'test@example.com', 'superadmin@example.com')
       OR email LIKE '%@example.com';
" 2>/dev/null | tr -d '[:space:]')

TOTAL_USERS_COUNT=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT COUNT(*) FROM erp_trading.users;
" 2>/dev/null | tr -d '[:space:]')

if [ "$DEFAULT_USERS_COUNT" = "0" ]; then
    pass "Tidak ada akun pengguna default/dummy di database erp_trading (default=0, total=$TOTAL_USERS_COUNT)"
else
    fail "Ditemukan akun pengguna default di database erp_trading (ditemukan $DEFAULT_USERS_COUNT)!"
fi

# ------------------------------------------------------------------------------
# 17. Verifikasi Anti-Spoofing XFF, Rate Limiting Nginx, & OOM Safety (Revisi Fase 3.1)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 17: Verifikasi Anti-Spoofing XFF, Nginx Zone, & OOM Safety ---"

# Catat jumlah baris laravel.log sebelum probe throttle (Langkah 0 F7)
LARAVEL_LOG_BEFORE_LINES=$(docker compose exec -T php sh -c 'if [ -f storage/logs/laravel.log ]; then wc -l < storage/logs/laravel.log; else echo 0; fi' 2>/dev/null | tr -d '\r\n')

# 1. Jalankan scripts/probe-login-throttle.sh
if bash scripts/probe-login-throttle.sh >/dev/null 2>&1; then
    pass "Probe login throttle anti-spoofing X-Forwarded-For lulus (scripts/probe-login-throttle.sh exit 0)"
else
    fail "Probe login throttle anti-spoofing X-Forwarded-For GAGAL"
fi

# 2. Verifikasi Nginx memuat zona login
NGINX_CONF=$(docker compose exec -T web nginx -T 2>/dev/null || true)
if echo "$NGINX_CONF" | grep -q 'zone=login_limit'; then
    pass "Nginx memuat definisi zona rate limit login (zone=login_limit)"
else
    fail "Nginx TIDAK memuat zona login_limit"
fi

# 3. Verifikasi status 429 pada Nginx
if echo "$NGINX_CONF" | grep -q 'limit_req_status 429'; then
    pass "Nginx dikonfigurasi mengembalikan status 429 saat rate limit terlampaui (limit_req_status 429)"
else
    fail "Nginx TIDAK mengonfigurasi limit_req_status 429"
fi

# 4. Verifikasi tidak ada rentang privat hardcoded di trustProxies
BOOTSTRAP_APP=$(cat src/bootstrap/app.php)
CONFIG_APP=$(cat src/config/app.php)
if echo "$BOOTSTRAP_APP $CONFIG_APP" | grep -Eq '10\.0\.0\.0/8|172\.16\.0\.0/12|192\.168\.0\.0/16'; then
    fail "Ditemukan rentang privat hardcoded di trustProxies (bootstrap/app.php atau config/app.php)!"
else
    pass "Tidak ada rentang IP privat hardcoded di trustProxies (bootstrap/app.php dan config/app.php bersih)"
fi

# 5. Verifikasi OOMKilled=false pada erp-trading-php
PHP_OOM=$(docker inspect erp-trading-php --format '{{.State.OOMKilled}}' 2>/dev/null || true)
if [ "$PHP_OOM" = "false" ]; then
    pass "Container erp-trading-php tidak pernah terkena OOM-killer (OOMKilled=false)"
else
    fail "Container erp-trading-php terdeteksi OOMKilled ($PHP_OOM)!"
fi

# ------------------------------------------------------------------------------
# 18. Verifikasi Ketiadaan Log Stack Trace pada Throttle (Fase 4 / Langk. 0 F1 & F7)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 18: Verifikasi Log PHP Bebas dari TooManyAttemptsException & Stack Trace ---"

PHP_LOGS=$(docker logs erp-trading-php --since 5m 2>&1 || true)
if echo "$PHP_LOGS" | grep -Ei 'TooManyAttemptsException|Stack trace'; then
    fail "Ditemukan TooManyAttemptsException atau Stack trace pada log PHP container!"
else
    pass "Log PHP container bersih dari TooManyAttemptsException dan Stack trace pasca probe"
fi

# F7: Perbandingan baris storage/logs/laravel.log sebelum dan sesudah probe throttle
LARAVEL_LOG_AFTER_LINES=$(docker compose exec -T php sh -c 'if [ -f storage/logs/laravel.log ]; then wc -l < storage/logs/laravel.log; else echo 0; fi' 2>/dev/null | tr -d '\r\n')
LARAVEL_LOG_BEFORE_LINES="${LARAVEL_LOG_BEFORE_LINES:-0}"

if [ -n "$LARAVEL_LOG_AFTER_LINES" ] && [ "$LARAVEL_LOG_AFTER_LINES" -gt "$LARAVEL_LOG_BEFORE_LINES" ]; then
    START_LINE=$((LARAVEL_LOG_BEFORE_LINES + 1))
    NEW_LOG_LINES=$(docker compose exec -T php sed -n "${START_LINE},${LARAVEL_LOG_AFTER_LINES}p" storage/logs/laravel.log 2>/dev/null || true)
    if echo "$NEW_LOG_LINES" | grep -Ei 'TooManyAttemptsException'; then
        fail "Ditemukan TooManyAttemptsException pada baris baru storage/logs/laravel.log pasca probe throttle!"
    else
        pass "Baris baru storage/logs/laravel.log ($((LARAVEL_LOG_AFTER_LINES - LARAVEL_LOG_BEFORE_LINES)) baris) bebas dari TooManyAttemptsException"
    fi
else
    pass "storage/logs/laravel.log tidak bertambah pasca probe throttle (bersih dari log exception)"
fi

# ------------------------------------------------------------------------------
# 19. Verifikasi Audit Dependensi Composer & Penanganan Advisori (Langkah 0 F6)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 19: Verifikasi Audit Dependensi Composer & Penanganan Advisori ---"

AUDIT_RAW=$(docker compose exec -T php composer audit --format=json 2>/dev/null || true)

if [ -z "$AUDIT_RAW" ]; then
    fail "composer audit tidak menghasilkan keluaran"
else
    AUDIT_CHECK=$(docker compose exec -T php php -r '
        $raw = file_get_contents("php://stdin");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo "INVALID_JSON";
            exit(1);
        }
        $allowlist = [];
        $expiry = "";
        $today = date("Y-m-d");

        $advisories = $data["advisories"] ?? [];
        $unallowed = [];
        $allowedFound = [];

        foreach ($advisories as $pkg => $pkgAdvisories) {
            foreach ($pkgAdvisories as $adv) {
                $id = $adv["advisoryId"] ?? "";
                if (in_array($id, $allowlist, true)) {
                    $allowedFound[] = "$pkg: $id ({$adv["title"]})";
                } else {
                    $unallowed[] = "$pkg: $id ({$adv["title"]})";
                }
            }
        }

        if (!empty($unallowed)) {
            echo "UNALLOWED:" . implode("; ", $unallowed);
            exit(2);
        }

        if (!empty($allowedFound)) {
            if ($today > $expiry) {
                echo "EXPIRED: Pengecualian advisori telah kedaluwarsa sejak $expiry (hari ini: $today)";
                exit(3);
            }
            echo "ALLOWED:" . implode("; ", $allowedFound);
            exit(0);
        }

        echo "CLEAN";
        exit(0);
    ' <<< "$AUDIT_RAW" 2>&1 || true)

    if [[ "$AUDIT_CHECK" == ALLOWED:* ]]; then
        ALLOWED_DETAILS="${AUDIT_CHECK#ALLOWED:}"
        warn "composer audit menemukan advisori yang dikecualikan (berlaku s.d. 2026-11-30): $ALLOWED_DETAILS"
        pass "composer audit bebas dari advisori di luar allowlist (pengecualian aktif valid s.d. 2026-11-30)"
    elif [ "$AUDIT_CHECK" = "CLEAN" ]; then
        pass "composer audit bersih dari semua kerentanan (0 advisories)"
    elif [[ "$AUDIT_CHECK" == EXPIRED:* ]]; then
        fail "Pengecualian advisori telah kedaluwarsa: ${AUDIT_CHECK#EXPIRED:}"
    elif [[ "$AUDIT_CHECK" == UNALLOWED:* ]]; then
        fail "Ditemukan advisori composer yang tidak diizinkan: ${AUDIT_CHECK#UNALLOWED:}"
    else
        fail "Gagal mengevaluasi hasil composer audit ($AUDIT_CHECK)"
    fi
fi

# ------------------------------------------------------------------------------
# Butir 20: Verifikasi Isolasi Route Dev / Styleguide (Hanya Aktif di APP_ENV=local)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 20: Verifikasi Isolasi Route Dev / Styleguide ---"
info "Memeriksa isolasi dan perlindungan route /dev/styleguide..."

STYLEGUIDE_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/dev/styleguide" || true)
if [ "$STYLEGUIDE_HTTP_CODE" = "302" ]; then
    pass "Endpoint /dev/styleguide terlindungi autentikasi di lingkungan lokal (mengembalikan HTTP 302 redirect ke /login)"
else
    fail "Endpoint /dev/styleguide mengembalikan kode HTTP tidak terduga di lokal ($STYLEGUIDE_HTTP_CODE)"
fi

PROD_ROUTE_CHECK=$(docker compose run --rm -T -e APP_ENV=production php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    try {
        $app["router"]->getRoutes()->match(Illuminate\Http\Request::create("/dev/styleguide", "GET"));
        echo "FOUND";
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        echo "NOT_FOUND";
    }
' 2>/dev/null || true)

if [ "$PROD_ROUTE_CHECK" = "NOT_FOUND" ]; then
    pass "Route /dev/styleguide tidak terdaftar di environment production (mengembalikan 404)"
else
    fail "Route /dev/styleguide terdaftar/bocor di environment production ($PROD_ROUTE_CHECK)"
fi

# ------------------------------------------------------------------------------
# Butir 21: Verifikasi Aset Frontend Mandiri & Self-Hosted (Strictly Zero CDN)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 21: Verifikasi Aset Frontend Mandiri (Strictly Self-Hosted) ---"
info "Memeriksa aset build frontend di src/public/build/..."

if [ ! -d src/public/build ]; then
    fail "Direktori build frontend (src/public/build) tidak ditemukan"
else
    pass "Direktori build frontend (src/public/build) tersedia"

    CDN_LEAKS=$(grep -rE "https?://(unpkg\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|cdn\.tailwindcss\.com|fonts\.googleapis\.com|fonts\.gstatic\.com)" src/public/build/ 2>/dev/null || true)
    if [ -z "$CDN_LEAKS" ]; then
        pass "Aset build frontend sepenuhnya mandiri / self-hosted (0 referensi CDN luar)"
    else
        fail "Ditemukan referensi CDN eksternal di dalam berkas build: $CDN_LEAKS"
    fi
fi

# ------------------------------------------------------------------------------
# Butir 22: Verifikasi Keamanan Rute & Otorisasi Master Data (/master/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 22: Verifikasi Keamanan Rute & Otorisasi Master Data (/master/*) ---"
info "Memeriksa seluruh rute /master/* terhadap autentikasi dan middleware can:..."

MASTER_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $masterRoutes = [];
    $unprotected = [];
    $missingCan = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, "master/")) {
            $masterRoutes[] = $uri;
            $middleware = $route->gatherMiddleware();
            if (!in_array("auth", $middleware, true)) {
                $unprotected[] = $uri;
            }
            $hasCan = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, "can:")) {
                    $hasCan = true;
                    break;
                }
            }
            if (!$hasCan) {
                $missingCan[] = $uri;
            }
        }
    }

    echo count($masterRoutes) . ":" . count($unprotected) . ":" . count($missingCan);
' 2>/dev/null || echo "0:1:1")

IFS=':' read -r TOTAL_M UNPROT_M MISSING_CAN <<< "$MASTER_ROUTE_CHECK"

if [ "$TOTAL_M" -ge 40 ]; then
    pass "Ditemukan $TOTAL_M rute master data (/master/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute master data tidak sesuai ekspektasi (ditemukan: $TOTAL_M)"
fi

if [ "$UNPROT_M" -eq 0 ]; then
    pass "Seluruh rute /master/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_M rute /master/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN" -eq 0 ]; then
    pass "Seluruh rute /master/* memiliki middleware otorisasi spesifik 'can:' (0 rute tanpa 'can:')"
else
    fail "Ditemukan $MISSING_CAN rute /master/* yang tidak memiliki middleware 'can:'"
fi

# Cek HTTP status redirect 302 untuk unauthenticated probe
GUEST_MASTER_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/master/units" || true)
if [ "$GUEST_MASTER_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /master/units berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /master/units mengembalikan status HTTP $GUEST_MASTER_CODE (harus 302)"
fi

# Cek DemoMasterDataSeeder failsafe di production
DEMO_SEEDER_PROD_CHECK=$(docker compose exec -T -e APP_ENV=production php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    try {
        $seeder = new Database\Seeders\DemoMasterDataSeeder();
        $seeder->run();
        echo "ALLOWED";
    } catch (\RuntimeException $e) {
        echo "BLOCKED";
    }
' 2>/dev/null || echo "ERROR")

if [ "$DEMO_SEEDER_PROD_CHECK" = "BLOCKED" ]; then
    pass "DemoMasterDataSeeder menolak eksekusi pada environment production (fail-safe aktif)"
else
    fail "DemoMasterDataSeeder dapat dieksekusi di production atau error ($DEMO_SEEDER_PROD_CHECK)"
fi

# ------------------------------------------------------------------------------
# Butir 23: Verifikasi Keamanan Rute & Otorisasi Inventori (/inventory/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 23: Verifikasi Keamanan Rute & Otorisasi Inventori (/inventory/*) ---"
info "Memeriksa seluruh rute /inventory/* terhadap autentikasi, can:, dan throttle:erp-write..."

INV_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $invRoutes = [];
    $unprotected = [];
    $missingCan = [];
    $missingThrottle = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, "inventory/")) {
            $invRoutes[] = $uri;
            $methods = $route->methods();
            $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;
            $middleware = $route->gatherMiddleware();
            if (!in_array("auth", $middleware, true)) {
                $unprotected[] = $uri;
            }
            $hasCan = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, "can:")) {
                    $hasCan = true;
                    break;
                }
            }
            if (!$hasCan) {
                $missingCan[] = $uri;
            }
            if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
                $missingThrottle[] = $uri;
            }
        }
    }

    echo count($invRoutes) . ":" . count($unprotected) . ":" . count($missingCan) . ":" . count($missingThrottle);
' 2>/dev/null || echo "0:1:1:1")

IFS=':' read -r TOTAL_INV UNPROT_INV MISSING_CAN_INV MISSING_THROTTLE_INV <<< "$INV_ROUTE_CHECK"

if [ "$TOTAL_INV" -eq 12 ]; then
    pass "Ditemukan 12 rute modul inventori (/inventory/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute modul inventori tidak sesuai ekspektasi (harus 12, ditemukan: $TOTAL_INV)"
fi

if [ "$UNPROT_INV" -eq 0 ]; then
    pass "Seluruh rute /inventory/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_INV rute /inventory/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN_INV" -eq 0 ]; then
    pass "Seluruh rute /inventory/* memiliki middleware otorisasi spesifik 'can:' (0 rute tanpa 'can:')"
else
    fail "Ditemukan $MISSING_CAN_INV rute /inventory/* yang tidak memiliki middleware 'can:'"
fi

if [ "$MISSING_THROTTLE_INV" -eq 0 ]; then
    pass "Seluruh aksi mutasi /inventory/* terlindungi oleh rate limiter 'throttle:erp-write'"
else
    fail "Ditemukan $MISSING_THROTTLE_INV rute mutasi /inventory/* tanpa 'throttle:erp-write'"
fi

GUEST_INV_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/inventory/stock-balances" || true)
if [ "$GUEST_INV_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /inventory/stock-balances berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /inventory/stock-balances mengembalikan status HTTP $GUEST_INV_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# Butir 24: Verifikasi Keamanan Rute & Otorisasi Pembelian (/purchasing/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 24: Verifikasi Keamanan Rute & Otorisasi Pembelian (/purchasing/*) ---"
info "Memeriksa seluruh rute /purchasing/* terhadap autentikasi, can:, dan throttle:erp-write..."

PURCH_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $purchRoutes = [];
    $unprotected = [];
    $missingCan = [];
    $missingThrottle = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, "purchasing/")) {
            $purchRoutes[] = $uri;
            $methods = $route->methods();
            $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;
            $middleware = $route->gatherMiddleware();
            if (!in_array("auth", $middleware, true)) {
                $unprotected[] = $uri;
            }
            $hasCan = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, "can:")) {
                    $hasCan = true;
                    break;
                }
            }
            if (!$hasCan) {
                $missingCan[] = $uri;
            }
            if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
                $missingThrottle[] = $uri;
            }
        }
    }

    echo count($purchRoutes) . ":" . count($unprotected) . ":" . count($missingCan) . ":" . count($missingThrottle);
' 2>/dev/null || echo "0:1:1:1")

IFS=':' read -r TOTAL_PURCH UNPROT_PURCH MISSING_CAN_PURCH MISSING_THROTTLE_PURCH <<< "$PURCH_ROUTE_CHECK"

if [ "$TOTAL_PURCH" -eq 28 ]; then
    pass "Ditemukan 28 rute modul purchasing (/purchasing/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute modul purchasing tidak sesuai ekspektasi (harus 28, ditemukan: $TOTAL_PURCH)"
fi

if [ "$UNPROT_PURCH" -eq 0 ]; then
    pass "Seluruh rute /purchasing/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_PURCH rute /purchasing/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN_PURCH" -eq 0 ]; then
    pass "Seluruh rute /purchasing/* memiliki middleware otorisasi spesifik 'can:' (0 rute tanpa 'can:')"
else
    fail "Ditemukan $MISSING_CAN_PURCH rute /purchasing/* yang tidak memiliki middleware 'can:'"
fi

if [ "$MISSING_THROTTLE_PURCH" -eq 0 ]; then
    pass "Seluruh aksi mutasi /purchasing/* terlindungi oleh rate limiter 'throttle:erp-write'"
else
    fail "Ditemukan $MISSING_THROTTLE_PURCH rute mutasi /purchasing/* tanpa 'throttle:erp-write'"
fi

GUEST_PURCH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/purchasing/purchase-orders" || true)
if [ "$GUEST_PURCH_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /purchasing/purchase-orders berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /purchasing/purchase-orders mengembalikan status HTTP $GUEST_PURCH_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# Butir 25: Verifikasi Rate Limiting Rute Tulis Global (throttle:erp-write)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 25: Verifikasi Rate Limiting Rute Tulis Global (throttle:erp-write) ---"
info "Memeriksa seluruh rute non-GET aplikasi terhadap middleware throttle:erp-write..."

GLOBAL_WRITE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $unthrottledWrites = [];
    $totalWrites = 0;

    foreach ($routes as $route) {
        $methods = array_diff($route->methods(), ["GET", "HEAD", "OPTIONS"]);
        if (empty($methods)) {
            continue;
        }
        $totalWrites++;
        $uri = $route->uri();
        if ($uri === "login") {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $hasThrottle = false;
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, "throttle:")) {
                $hasThrottle = true;
                break;
            }
        }
        if (!$hasThrottle) {
            $unthrottledWrites[] = implode("|", $methods) . " " . $uri;
        }
    }

    echo $totalWrites . ":" . count($unthrottledWrites);
' 2>/dev/null || echo "0:1")

IFS=':' read -r TOTAL_WRITES UNTHROTTLED_WRITES <<< "$GLOBAL_WRITE_CHECK"

if [ "$UNTHROTTLED_WRITES" -eq 0 ]; then
    pass "Seluruh rute tulis aplikasi ($TOTAL_WRITES rute) terlindungi oleh limiter (0 rute tanpa limiter)"
else
    fail "Ditemukan $UNTHROTTLED_WRITES rute tulis tanpa rate limiter"
fi

# Kontrol positif: pastikan GET /login tidak terpasang throttle:erp-write
GET_LOGIN_THROTTLE=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $route = $app["router"]->getRoutes()->getByName("login");
    $hasErpWrite = $route ? in_array("throttle:erp-write", $route->gatherMiddleware(), true) : false;
    echo $hasErpWrite ? "yes" : "no";
' 2>/dev/null || echo "unknown")

if [ "$GET_LOGIN_THROTTLE" = "no" ]; then
    pass "Rute publik GET /login bebas dari limiter tulis throttle:erp-write"
else
    fail "Rute publik GET /login keliru dipasangi throttle:erp-write"
fi

# ------------------------------------------------------------------------------
# 26. Verifikasi Aset Statis Nginx (Kompresi Gzip, Cache Immutable, Nosniff)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 26: Verifikasi Aset Statis Nginx (Gzip, Cache-Control, Nosniff) ---"

# Cari berkas aset nyata di src/public/build/assets/*.js
REAL_ASSET=$(find src/public/build/assets -name "*.js" -size +1k -print -quit)

if [ -n "$REAL_ASSET" ] && [ -f "$REAL_ASSET" ]; then
    ASSET_NAME=$(basename "$REAL_ASSET")
    ASSET_HEADER=$(curl -sI -H 'Accept-Encoding: gzip' "http://127.0.0.1:${APP_PORT:-8080}/build/assets/${ASSET_NAME}")

    if echo "$ASSET_HEADER" | grep -qi "Content-Encoding: gzip"; then
        pass "Aset statis /build/assets/$ASSET_NAME terkompresi dengan gzip (Content-Encoding: gzip)"
    else
        fail "Aset statis /build/assets/$ASSET_NAME TIDAK memiliki header Content-Encoding: gzip"
    fi

    if echo "$ASSET_HEADER" | grep -qi "Cache-Control:.*immutable"; then
        pass "Aset statis /build/assets/$ASSET_NAME memiliki header Cache-Control immutable"
    else
        fail "Aset statis /build/assets/$ASSET_NAME TIDAK memiliki header Cache-Control immutable"
    fi

    if echo "$ASSET_HEADER" | grep -qi "X-Content-Type-Options: nosniff"; then
        pass "Aset statis /build/assets/$ASSET_NAME memiliki header X-Content-Type-Options: nosniff"
    else
        fail "Aset statis /build/assets/$ASSET_NAME TIDAK memiliki header X-Content-Type-Options: nosniff"
    fi
else
    fail "Tidak ditemukan berkas aset JS berukuran > 1KB di src/public/build/assets untuk pengujian"
fi

# ------------------------------------------------------------------------------
# 27. Verifikasi Keamanan Rute & Otorisasi Penjualan (/sales/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 27: Verifikasi Keamanan Rute & Otorisasi Penjualan (/sales/*) ---"
info "Memeriksa seluruh rute /sales/* terhadap autentikasi, can:, dan throttle:erp-write..."

SALES_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $salesRoutes = [];
    $unprotected = [];
    $missingCan = [];
    $missingThrottle = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, "sales/")) {
            $salesRoutes[] = $uri;
            $methods = $route->methods();
            $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;
            $middleware = $route->gatherMiddleware();
            if (!in_array("auth", $middleware, true)) {
                $unprotected[] = $uri;
            }
            $hasCan = false;
            $hasThrottle = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, "can:")) {
                    $hasCan = true;
                }
                if (is_string($m) && str_starts_with($m, "throttle:")) {
                    $hasThrottle = true;
                }
            }
            if (!$hasCan) {
                $missingCan[] = $uri;
            }
            if ($isMutation && !$hasThrottle) {
                $missingThrottle[] = implode("|", $methods) . " " . $uri;
            }
        }
    }

    echo count($salesRoutes) . ":" . count($unprotected) . ":" . count($missingCan) . ":" . count($missingThrottle);
' 2>/dev/null || echo "0:1:1:1")

IFS=':' read -r TOTAL_SALES UNPROT_SALES MISSING_CAN_SALES MISSING_THROTTLE_SALES <<< "$SALES_ROUTE_CHECK"

if [ "$TOTAL_SALES" -eq 28 ]; then
    pass "Ditemukan 28 rute modul penjualan (/sales/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute modul penjualan tidak sesuai ekspektasi (harus 28, ditemukan: $TOTAL_SALES)"
fi

if [ "$UNPROT_SALES" -eq 0 ]; then
    pass "Seluruh rute /sales/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_SALES rute /sales/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN_SALES" -eq 0 ]; then
    pass "Seluruh rute /sales/* memiliki middleware otorisasi spesifik 'can:' (0 rute tanpa 'can:')"
else
    fail "Ditemukan $MISSING_CAN_SALES rute /sales/* yang tidak memiliki middleware 'can:'"
fi

if [ "$MISSING_THROTTLE_SALES" -eq 0 ]; then
    pass "Seluruh aksi mutasi /sales/* terlindungi oleh rate limiter 'throttle:erp-write'"
else
    fail "Ditemukan $MISSING_THROTTLE_SALES rute mutasi /sales/* tanpa 'throttle:erp-write'"
fi

GUEST_SALES_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/sales/sales-orders" || true)
if [ "$GUEST_SALES_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /sales/sales-orders berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /sales/sales-orders mengembalikan status HTTP $GUEST_SALES_CODE (harus 302)"
fi

GUEST_DO_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/sales/delivery-orders" || true)
if [ "$GUEST_DO_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /sales/delivery-orders berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /sales/delivery-orders mengembalikan status HTTP $GUEST_DO_CODE (harus 302)"
fi

echo "--- Butir 28: Verifikasi Keamanan Rute & Otorisasi Akuntansi (/accounting/*) ---"
info "Memeriksa seluruh rute /accounting/* terhadap autentikasi, can:, dan throttle:erp-write..."

ACCOUNTING_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $routes = $app["router"]->getRoutes();
    $accountingRoutes = [];
    $unprotected = [];
    $missingCan = [];
    $missingThrottle = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, "accounting/")) {
            $accountingRoutes[] = $uri;
            $methods = $route->methods();
            $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;
            $middleware = $route->gatherMiddleware();
            if (!in_array("auth", $middleware, true)) {
                $unprotected[] = $uri;
            }
            $hasCan = false;
            $hasThrottle = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, "can:")) {
                    $hasCan = true;
                }
                if (is_string($m) && str_starts_with($m, "throttle:")) {
                    $hasThrottle = true;
                }
            }
            if (!$hasCan) {
                $missingCan[] = $uri;
            }
            if ($isMutation && !$hasThrottle) {
                $missingThrottle[] = implode("|", $methods) . " " . $uri;
            }
        }
    }

    echo count($accountingRoutes) . ":" . count($unprotected) . ":" . count($missingCan) . ":" . count($missingThrottle);
' 2>/dev/null || echo "0:1:1:1")

IFS=':' read -r TOTAL_ACCOUNTING UNPROT_ACCOUNTING MISSING_CAN_ACCOUNTING MISSING_THROTTLE_ACCOUNTING <<< "$ACCOUNTING_ROUTE_CHECK"

if [ "$TOTAL_ACCOUNTING" -eq 25 ]; then
    pass "Ditemukan 25 rute modul akuntansi (/accounting/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute modul akuntansi tidak sesuai ekspektasi (harus 25, ditemukan: $TOTAL_ACCOUNTING)"
fi

if [ "$UNPROT_ACCOUNTING" -eq 0 ]; then
    pass "Seluruh rute /accounting/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_ACCOUNTING rute /accounting/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN_ACCOUNTING" -eq 0 ]; then
    pass "Seluruh rute /accounting/* memiliki middleware otorisasi spesifik 'can:' (0 rute tanpa 'can:')"
else
    fail "Ditemukan $MISSING_CAN_ACCOUNTING rute /accounting/* yang tidak memiliki middleware 'can:'"
fi

if [ "$MISSING_THROTTLE_ACCOUNTING" -eq 0 ]; then
    pass "Seluruh aksi mutasi /accounting/* terlindungi oleh rate limiter 'throttle:erp-write'"
else
    fail "Ditemukan $MISSING_THROTTLE_ACCOUNTING rute mutasi /accounting/* tanpa 'throttle:erp-write'"
fi

GUEST_JOURNALS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/accounting/journals" || true)
if [ "$GUEST_JOURNALS_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /accounting/journals berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /accounting/journals mengembalikan status HTTP $GUEST_JOURNALS_CODE (harus 302)"
fi

GUEST_ACCOUNTS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/accounting/accounts" || true)
if [ "$GUEST_ACCOUNTS_CODE" = "302" ]; then
    pass "Akses guest tanpa sesi ke /accounting/accounts berhasil dialihkan (HTTP 302 ke halaman login)"
else
    fail "Akses guest tanpa sesi ke /accounting/accounts mengembalikan status HTTP $GUEST_ACCOUNTS_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 29. Verifikasi Keamanan Rute & Otorisasi Administrasi (/admin/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 29: Verifikasi Keamanan Rute & Otorisasi Administrasi (/admin/*) ---"
info "Memeriksa seluruh rute /admin/* terhadap autentikasi, can: spesifik, dan throttle:erp-write..."

ADMIN_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $total = 0;
    $unprotected = 0;
    $missingCan = 0;
    $missingThrottle = 0;

    foreach ($app["router"]->getRoutes() as $route) {
        if (!str_starts_with($route->uri(), "admin/")) {
            continue;
        }
        $total++;
        $middleware = array_filter($route->gatherMiddleware(), "is_string");
        if (!in_array("auth", $middleware, true)) {
            $unprotected++;
        }
        if (count(preg_grep("/^can:(users\.(view|create|update|delete)|roles\.(view|create|update|delete)|audit_logs\.view)$/", $middleware)) !== 1) {
            $missingCan++;
        }
        $isMutation = count(array_intersect($route->methods(), ["POST", "PUT", "PATCH", "DELETE"])) > 0;
        if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
            $missingThrottle++;
        }
    }

    echo $total . ":" . $unprotected . ":" . $missingCan . ":" . $missingThrottle;
' 2>/dev/null || echo "0:1:1:1")

IFS=':' read -r TOTAL_ADMIN UNPROT_ADMIN MISSING_CAN_ADMIN MISSING_THROTTLE_ADMIN <<< "$ADMIN_ROUTE_CHECK"

if [ "$TOTAL_ADMIN" -eq 17 ]; then
    pass "Ditemukan 17 rute modul administrasi (/admin/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute modul administrasi tidak sesuai ekspektasi (harus 17, ditemukan: $TOTAL_ADMIN)"
fi

if [ "$UNPROT_ADMIN" -eq 0 ]; then
    pass "Seluruh rute /admin/* terlindungi oleh middleware 'auth' (0 rute tanpa autentikasi)"
else
    fail "Ditemukan $UNPROT_ADMIN rute /admin/* yang tidak memiliki middleware 'auth'"
fi

if [ "$MISSING_CAN_ADMIN" -eq 0 ]; then
    pass "Seluruh rute /admin/* memiliki tepat satu middleware 'can:' spesifik (users.*, roles.*, audit_logs.view)"
else
    fail "Ditemukan $MISSING_CAN_ADMIN rute /admin/* tanpa middleware 'can:' spesifik"
fi

if [ "$MISSING_THROTTLE_ADMIN" -eq 0 ]; then
    pass "Seluruh aksi mutasi /admin/* terlindungi oleh rate limiter 'throttle:erp-write'"
else
    fail "Ditemukan $MISSING_THROTTLE_ADMIN rute mutasi /admin/* tanpa 'throttle:erp-write'"
fi

for ADMIN_GUEST_PATH in /admin/users /admin/roles /admin/audit-logs; do
    GUEST_ADMIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}${ADMIN_GUEST_PATH}" || true)
    if [ "$GUEST_ADMIN_CODE" = "302" ]; then
        pass "Akses guest tanpa sesi ke ${ADMIN_GUEST_PATH} berhasil dialihkan (HTTP 302 ke halaman login)"
    else
        fail "Akses guest tanpa sesi ke ${ADMIN_GUEST_PATH} mengembalikan status HTTP $GUEST_ADMIN_CODE (harus 302)"
    fi
done

# The probe user and models exist only in memory, so nothing is written to the database and no account is created.
info "Memeriksa bahwa setiap rute /admin/* menjawab 403 untuk pengguna tanpa izin..."

ADMIN_FORBIDDEN_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    config(["session.driver" => "array"]);
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(["*"]);

    $router = $app["router"];
    $router->bind("user", fn () => new App\Models\User);
    $router->bind("role", fn () => new App\Models\Role);

    $probe = new App\Models\User;
    $probe->forceFill(["id" => PHP_INT_MAX, "is_active" => true]);
    $probe->setRelation("roles", new Illuminate\Database\Eloquent\Collection);
    $app["auth"]->guard("web")->setUser($probe);

    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $checked = 0;
    $wrong = [];

    foreach ($router->getRoutes() as $route) {
        if (!str_starts_with($route->uri(), "admin/")) {
            continue;
        }
        $method = array_values(array_diff($route->methods(), ["HEAD"]))[0];
        $uri = "/" . preg_replace("/\{[a-z_]+\}/", "1", $route->uri());
        $status = $http->handle(Illuminate\Http\Request::create($uri, $method))->getStatusCode();
        $checked++;
        if ($status !== 403) {
            $wrong[] = $method . " " . $uri . "=" . $status;
        }
    }

    echo $checked . ":" . count($wrong) . ":" . implode(",", $wrong);
' 2>/dev/null || echo "0:1:gagal-dijalankan")

IFS=':' read -r ADMIN_FORBIDDEN_TOTAL ADMIN_FORBIDDEN_WRONG ADMIN_FORBIDDEN_LIST <<< "$ADMIN_FORBIDDEN_CHECK"

if [ "$ADMIN_FORBIDDEN_TOTAL" -eq 17 ] && [ "$ADMIN_FORBIDDEN_WRONG" -eq 0 ]; then
    pass "Seluruh 17 rute /admin/* menjawab HTTP 403 untuk pengguna tanpa izin"
else
    fail "Rute /admin/* tidak menjawab 403 untuk pengguna tanpa izin (diperiksa: $ADMIN_FORBIDDEN_TOTAL, keliru: $ADMIN_FORBIDDEN_WRONG; $ADMIN_FORBIDDEN_LIST)"
fi

# ------------------------------------------------------------------------------
# 31. Keamanan rute faktur penjualan (/sales/invoices*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 31: Verifikasi Keamanan Rute & Otorisasi Faktur Penjualan (/sales/invoices*) ---"

INVOICE_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $total = 0;
    $bad = [];

    foreach ($app["router"]->getRoutes() as $route) {
        $uri = $route->uri();

        if ($uri !== "sales/invoices" && !str_starts_with($uri, "sales/invoices/")) {
            continue;
        }

        $total++;
        $methods = $route->methods();
        $middleware = $route->gatherMiddleware();
        $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;

        if (!in_array("auth", $middleware, true)) {
            $bad[] = "auth " . $uri;
        }

        if (count(preg_grep("/^can:sales_invoices\.(view|create|update|post|void)$/", $middleware)) !== 1) {
            $bad[] = "can " . implode("|", $methods) . " " . $uri;
        }

        if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
            $bad[] = "throttle " . implode("|", $methods) . " " . $uri;
        }
    }

    echo $total . ":" . count($bad) . ":" . implode(",", $bad);
' 2>/dev/null || echo "0:1:gagal-dijalankan")

IFS=':' read -r INVOICE_ROUTE_TOTAL INVOICE_ROUTE_BAD INVOICE_ROUTE_LIST <<< "$INVOICE_ROUTE_CHECK"

if [ "$INVOICE_ROUTE_TOTAL" -eq 9 ]; then
    pass "Ditemukan 9 rute faktur penjualan (/sales/invoices*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute faktur penjualan tidak sesuai ekspektasi (harus 9, ditemukan: $INVOICE_ROUTE_TOTAL)"
fi

if [ "$INVOICE_ROUTE_BAD" -eq 0 ]; then
    pass "Seluruh rute /sales/invoices* memiliki auth, satu 'can:sales_invoices.*', dan throttle:erp-write pada mutasi"
else
    fail "Rute /sales/invoices* tidak memenuhi syarat ($INVOICE_ROUTE_BAD: $INVOICE_ROUTE_LIST)"
fi

GUEST_INVOICE_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/sales/invoices" || true)
if [ "$GUEST_INVOICE_CODE" = "302" ]; then
    pass "Akses guest ke /sales/invoices dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke /sales/invoices mengembalikan HTTP $GUEST_INVOICE_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 32. Tampilan cetak faktur penjualan
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 32: Verifikasi Rute Cetak Faktur Penjualan ---"

INVOICE_PRINT_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    foreach ($app["router"]->getRoutes() as $route) {
        if ($route->uri() === "sales/invoices/{sales_invoice}/print") {
            $methods = array_values(array_diff($route->methods(), ["HEAD"]));
            echo implode("|", $methods) . ":" . (in_array("can:sales_invoices.view", $route->gatherMiddleware(), true) ? "ok" : "tanpa-izin");
            exit(0);
        }
    }

    echo "tidak-ada:-";
' 2>/dev/null || echo "gagal:-")

if [ "$INVOICE_PRINT_CHECK" = "GET:ok" ]; then
    pass "Rute cetak faktur hanya menerima GET dan dijaga 'can:sales_invoices.view'"
else
    fail "Rute cetak faktur tidak sesuai ($INVOICE_PRINT_CHECK)"
fi

GUEST_INVOICE_PRINT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/sales/invoices/1/print" || true)
if [ "$GUEST_INVOICE_PRINT_CODE" = "302" ]; then
    pass "Akses guest ke rute cetak faktur dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke rute cetak faktur mengembalikan HTTP $GUEST_INVOICE_PRINT_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 33. Keamanan rute pembayaran (/accounting/payments*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 33: Verifikasi Keamanan Rute & Otorisasi Pembayaran (/accounting/payments*) ---"

PAYMENT_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $total = 0;
    $bad = [];

    foreach ($app["router"]->getRoutes() as $route) {
        $uri = $route->uri();

        if ($uri !== "accounting/payments" && !str_starts_with($uri, "accounting/payments/")) {
            continue;
        }

        $total++;
        $methods = $route->methods();
        $middleware = $route->gatherMiddleware();
        $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;

        if (!in_array("auth", $middleware, true)) {
            $bad[] = "auth " . $uri;
        }

        if (count(preg_grep("/^can:payments\.(view|create|update|post|void)$/", $middleware)) !== 1) {
            $bad[] = "can " . implode("|", $methods) . " " . $uri;
        }

        if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
            $bad[] = "throttle " . implode("|", $methods) . " " . $uri;
        }
    }

    echo $total . ":" . count($bad) . ":" . implode(",", $bad);
' 2>/dev/null || echo "0:1:gagal-dijalankan")

IFS=':' read -r PAYMENT_ROUTE_TOTAL PAYMENT_ROUTE_BAD PAYMENT_ROUTE_LIST <<< "$PAYMENT_ROUTE_CHECK"

if [ "$PAYMENT_ROUTE_TOTAL" -eq 8 ]; then
    pass "Ditemukan 8 rute pembayaran (/accounting/payments*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute pembayaran tidak sesuai ekspektasi (harus 8, ditemukan: $PAYMENT_ROUTE_TOTAL)"
fi

if [ "$PAYMENT_ROUTE_BAD" -eq 0 ]; then
    pass "Seluruh rute /accounting/payments* memiliki auth, satu 'can:payments.*', dan throttle:erp-write pada mutasi"
else
    fail "Rute /accounting/payments* tidak memenuhi syarat ($PAYMENT_ROUTE_BAD: $PAYMENT_ROUTE_LIST)"
fi

GUEST_PAYMENT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/accounting/payments" || true)
if [ "$GUEST_PAYMENT_CODE" = "302" ]; then
    pass "Akses guest ke /accounting/payments dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke /accounting/payments mengembalikan HTTP $GUEST_PAYMENT_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 34. Keamanan rute faktur pembelian (/purchasing/invoices*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 34: Verifikasi Keamanan Rute & Otorisasi Faktur Pembelian (/purchasing/invoices*) ---"

PURCHASE_INVOICE_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $total = 0;
    $bad = [];

    foreach ($app["router"]->getRoutes() as $route) {
        $uri = $route->uri();

        if ($uri !== "purchasing/invoices" && !str_starts_with($uri, "purchasing/invoices/")) {
            continue;
        }

        $total++;
        $methods = $route->methods();
        $middleware = $route->gatherMiddleware();
        $isMutation = count(array_intersect($methods, ["POST", "PUT", "PATCH", "DELETE"])) > 0;

        if (!in_array("auth", $middleware, true)) {
            $bad[] = "auth " . $uri;
        }

        if (count(preg_grep("/^can:purchase_invoices\.(view|create|update|post|void)$/", $middleware)) !== 1) {
            $bad[] = "can " . implode("|", $methods) . " " . $uri;
        }

        if ($isMutation && !in_array("throttle:erp-write", $middleware, true)) {
            $bad[] = "throttle " . implode("|", $methods) . " " . $uri;
        }
    }

    echo $total . ":" . count($bad) . ":" . implode(",", $bad);
' 2>/dev/null || echo "0:1:gagal-dijalankan")

IFS=':' read -r PURCHASE_INVOICE_ROUTE_TOTAL PURCHASE_INVOICE_ROUTE_BAD PURCHASE_INVOICE_ROUTE_LIST <<< "$PURCHASE_INVOICE_ROUTE_CHECK"

if [ "$PURCHASE_INVOICE_ROUTE_TOTAL" -eq 9 ]; then
    pass "Ditemukan 9 rute faktur pembelian (/purchasing/invoices*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute faktur pembelian tidak sesuai ekspektasi (harus 9, ditemukan: $PURCHASE_INVOICE_ROUTE_TOTAL)"
fi

if [ "$PURCHASE_INVOICE_ROUTE_BAD" -eq 0 ]; then
    pass "Seluruh rute /purchasing/invoices* memiliki auth, satu 'can:purchase_invoices.*', dan throttle:erp-write pada mutasi"
else
    fail "Rute /purchasing/invoices* tidak memenuhi syarat ($PURCHASE_INVOICE_ROUTE_BAD: $PURCHASE_INVOICE_ROUTE_LIST)"
fi

GUEST_PURCHASE_INVOICE_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/purchasing/invoices" || true)
if [ "$GUEST_PURCHASE_INVOICE_CODE" = "302" ]; then
    pass "Akses guest ke /purchasing/invoices dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke /purchasing/invoices mengembalikan HTTP $GUEST_PURCHASE_INVOICE_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 35. Keamanan rute laporan (/reports/*)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 35: Verifikasi Keamanan Rute & Otorisasi Laporan (/reports/*) ---"

REPORT_ROUTE_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $total = 0;
    $bad = [];

    foreach ($app["router"]->getRoutes() as $route) {
        $uri = $route->uri();

        if (!str_starts_with($uri, "reports/")) {
            continue;
        }

        $total++;
        $methods = $route->methods();
        $middleware = $route->gatherMiddleware();

        if (!in_array("auth", $middleware, true)) {
            $bad[] = "auth " . $uri;
        }

        if (count(preg_grep("/^can:reports\.view_(finance|sales|purchasing|inventory)$/", $middleware)) !== 1) {
            $bad[] = "can " . $uri;
        }

        if (array_diff($methods, ["GET", "HEAD"]) !== []) {
            $bad[] = "tulis " . implode("|", $methods) . " " . $uri;
        }
    }

    echo $total . ":" . count($bad) . ":" . implode(",", $bad);
' 2>/dev/null || echo "0:1:gagal-dijalankan")

IFS=':' read -r REPORT_ROUTE_TOTAL REPORT_ROUTE_BAD REPORT_ROUTE_LIST <<< "$REPORT_ROUTE_CHECK"

if [ "$REPORT_ROUTE_TOTAL" -eq 11 ]; then
    pass "Ditemukan 11 rute laporan (/reports/*) terdaftar dalam aplikasi"
else
    fail "Jumlah rute laporan tidak sesuai ekspektasi (harus 11, ditemukan: $REPORT_ROUTE_TOTAL)"
fi

if [ "$REPORT_ROUTE_BAD" -eq 0 ]; then
    pass "Seluruh rute /reports/* hanya GET, memiliki auth, dan satu 'can:reports.view_*'"
else
    fail "Rute /reports/* tidak memenuhi syarat ($REPORT_ROUTE_BAD: $REPORT_ROUTE_LIST)"
fi

GUEST_REPORT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/reports/trial-balance" || true)
if [ "$GUEST_REPORT_CODE" = "302" ]; then
    pass "Akses guest ke /reports/trial-balance dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke /reports/trial-balance mengembalikan HTTP $GUEST_REPORT_CODE (harus 302)"
fi

GUEST_REPORT_EXPORT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/reports/trial-balance?export=csv" || true)
if [ "$GUEST_REPORT_EXPORT_CODE" = "302" ]; then
    pass "Akses guest ke ekspor CSV laporan dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke ekspor CSV laporan mengembalikan HTTP $GUEST_REPORT_EXPORT_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 37. Rute cetak dokumen
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 37: Verifikasi Rute Cetak Dokumen ---"

DOCUMENT_PRINT_CHECK=$(docker compose exec -T php php -r '
    require "vendor/autoload.php";
    $app = require_once "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $expected = [
        "purchasing/purchase-orders/{purchase_order}/print" => "can:purchase_orders.view",
        "purchasing/goods-receipts/{goods_receipt}/print" => "can:goods_receipts.view",
        "purchasing/invoices/{purchase_invoice}/print" => "can:purchase_invoices.view",
        "sales/sales-orders/{sales_order}/print" => "can:sales_orders.view",
        "sales/delivery-orders/{delivery_order}/print" => "can:delivery_orders.view",
        "inventory/stock-adjustments/{stock_adjustment}/print" => "can:stock_adjustments.view",
        "accounting/journals/{journal}/print" => "can:journals.view",
    ];
    $bad = array_keys($expected);

    foreach ($app["router"]->getRoutes() as $route) {
        $uri = $route->uri();

        if (isset($expected[$uri])
            && array_values(array_diff($route->methods(), ["HEAD"])) === ["GET"]
            && in_array($expected[$uri], $route->gatherMiddleware(), true)
            && in_array("auth", $route->gatherMiddleware(), true)) {
            $bad = array_values(array_diff($bad, [$uri]));
        }
    }

    echo count($bad) . ":" . implode(",", $bad);
' 2>/dev/null || echo "1:gagal-dijalankan")

IFS=':' read -r DOCUMENT_PRINT_BAD DOCUMENT_PRINT_LIST <<< "$DOCUMENT_PRINT_CHECK"

if [ "$DOCUMENT_PRINT_BAD" -eq 0 ]; then
    pass "Ketujuh rute cetak dokumen hanya GET, memiliki auth, dan izin lihat dokumennya"
else
    fail "Rute cetak dokumen tidak memenuhi syarat ($DOCUMENT_PRINT_BAD: $DOCUMENT_PRINT_LIST)"
fi

# ------------------------------------------------------------------------------
# 38. Ekspor daftar
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 38: Verifikasi Ekspor CSV Daftar ---"

GUEST_LIST_EXPORT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-8080}/master/customers?export=csv" || true)
if [ "$GUEST_LIST_EXPORT_CODE" = "302" ]; then
    pass "Akses guest ke ekspor CSV daftar dialihkan ke halaman login (HTTP 302)"
else
    fail "Akses guest ke ekspor CSV daftar mengembalikan HTTP $GUEST_LIST_EXPORT_CODE (harus 302)"
fi

# ------------------------------------------------------------------------------
# 36. Tautan dokumentasi
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 36: Verifikasi Tautan Relatif Dokumentasi ---"

if DOC_LINK_OUTPUT=$(bash scripts/check-doc-links.sh 2>&1); then
    pass "Tidak ada tautan relatif mati di README dan docs ($(echo "$DOC_LINK_OUTPUT" | tail -1))"
else
    fail "Ditemukan tautan relatif mati: $DOC_LINK_OUTPUT"
fi

# ------------------------------------------------------------------------------
# 30. Retensi log audit dan container penjadwal
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 30: Verifikasi Retensi Log Audit dan Container Penjadwal ---"

# SYSDATE, not NOW: NOW follows SET timestamp, which any session can change.
AUDIT_DELETE_TRIGGER=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = 'erp_trading' AND TRIGGER_NAME = 'tr_audit_logs_before_delete';
" 2>/dev/null)

if echo "$AUDIT_DELETE_TRIGGER" | grep -q "OLD.created_at >= SYSDATE() - INTERVAL 7 DAY"; then
    pass "Trigger hapus audit_logs menolak baris berumur kurang dari 7 hari berdasarkan SYSDATE()"
else
    fail "Trigger hapus audit_logs tidak memakai batas SYSDATE() 7 hari"
fi

AUDIT_UPDATE_TRIGGER=$(docker compose exec -T -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = 'erp_trading' AND TRIGGER_NAME = 'tr_audit_logs_before_update';
" 2>/dev/null)

if echo "$AUDIT_UPDATE_TRIGGER" | grep -q "SIGNAL" && ! echo "$AUDIT_UPDATE_TRIGGER" | grep -q "IF "; then
    pass "Trigger ubah audit_logs tetap menolak semua UPDATE tanpa syarat"
else
    fail "Trigger ubah audit_logs tidak lagi menolak semua UPDATE"
fi

SCHEDULE_LIST=$(docker compose exec -T scheduler php artisan schedule:list 2>/dev/null)
if echo "$SCHEDULE_LIST" | grep -q "erp:audit:prune"; then
    pass "Penghapusan log audit terjadwal pada container scheduler"
else
    fail "Penghapusan log audit tidak terjadwal pada container scheduler"
fi

SCHEDULER_ENV=$(docker compose exec -T scheduler env 2>/dev/null)
if echo "$SCHEDULER_ENV" | grep -qE "MIGRATOR|ROOT_PASSWORD"; then
    fail "Container scheduler memuat kredensial migrator atau root di environment"
else
    pass "Container scheduler tidak memuat kredensial migrator atau root di environment"
fi

SCHEDULER_NETWORKS=$(docker inspect erp-trading-scheduler --format '{{range $name, $conf := .NetworkSettings.Networks}}{{$name}} {{end}}' 2>/dev/null | xargs)
if [[ "$SCHEDULER_NETWORKS" =~ ^[a-zA-Z0-9_.-]*_backend$ ]]; then
    pass "Container scheduler hanya terhubung ke jaringan backend ($SCHEDULER_NETWORKS)"
else
    fail "Container scheduler terhubung ke jaringan lain ($SCHEDULER_NETWORKS)"
fi

SCHEDULER_PORTS=$(docker inspect erp-trading-scheduler --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}' 2>/dev/null | xargs)
if [ -z "$SCHEDULER_PORTS" ]; then
    pass "Container scheduler tidak mempublikasikan port ke host"
else
    fail "Container scheduler mempublikasikan port ke host ($SCHEDULER_PORTS)"
fi

# The image declares EXPOSE 9000 for FPM; this container must not actually run FPM.
if docker compose exec -T scheduler nc -z 127.0.0.1 9000 >/dev/null 2>&1; then
    fail "Container scheduler menjalankan layanan pada port 9000"
else
    pass "Container scheduler tidak menjalankan layanan pada port 9000"
fi

# ------------------------------------------------------------------------------
# Ringkasan Akhir
# ------------------------------------------------------------------------------
echo ""
echo "================================================================="
echo -e "HASIL VERIFIKASI: ${GREEN}$PASSED_COUNT PASS${NC} / ${RED}$FAILED_COUNT FAIL${NC}"
echo "================================================================="

if [ "$FAILED_COUNT" -gt 0 ]; then
    echo -e "${RED}VERIFIKASI GAGAL.${NC}"
    exit 1
fi

echo -e "${GREEN}SELURUH BUTIR PENGUJIAN HARDENING TELAH LULUS (PASS 100%).${NC}"
exit 0

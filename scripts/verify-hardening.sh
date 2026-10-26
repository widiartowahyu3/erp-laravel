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
    docker compose exec php rm -f "$PROBE_FPM_FILE" 2>/dev/null || true
    docker compose exec php rm -f "$PROBE_COMPOSER_WRITE" 2>/dev/null || true
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
info "Waiting for healthy status on web, php, db..."
for s in web php db; do
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

WEB_UID=$(docker compose exec web id -u 2>/dev/null | tr -d '\r')
PHP_UID=$(docker compose exec php id -u 2>/dev/null | tr -d '\r')
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

# ------------------------------------------------------------------------------
# 2. docker inspect: ReadonlyRootfs=true, no-new-privileges, CapDrop ALL
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 2: Verifikasi ReadonlyRootfs, no-new-privileges, CapDrop ALL ---"

for svc in web php db node; do
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
docker compose exec php sh -c "cat << 'EOF' > $PROBE_FPM_FILE
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

FPM_RAW=$(docker compose exec php sh -c "SCRIPT_FILENAME=$PROBE_FPM_FILE REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000" 2>/dev/null || true)
docker compose exec php rm -f "$PROBE_FPM_FILE" 2>/dev/null || true

FPM_JSON=$(echo "$FPM_RAW" | tr -d '\r' | grep -m 1 '^{' || true)

# Parse disable_functions secara spesifik dari JSON (bukan grep exec yang bisa cocok ke key exec_exists)
FPM_DISABLE_FUNCS=$(docker compose exec php php -r '
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
FPM_EXTS=$(docker compose exec php php -r '
    $raw = @file_get_contents("php://stdin");
    $data = json_decode($raw, true);
    echo implode(",", $data["extensions"] ?? []);
' <<< "$FPM_JSON" 2>/dev/null || true)

CLI_EXTS=$(docker compose exec php php -r 'echo implode(",", get_loaded_extensions());' 2>/dev/null || true)

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
CLI_PROC_OPEN=$(docker compose exec php php -r "echo function_exists('proc_open') ? 'yes' : 'no';" 2>/dev/null | tr -d '\r')
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

COMPOSER_VER=$(docker compose exec php composer --version 2>/dev/null | tr -d '\r')
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
DB_VERSION=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT VERSION();" 2>/dev/null | tr -d '\r')
if [[ "$DB_VERSION" =~ ^12\.3\. ]]; then
    pass "MariaDB versi 12.3.x ($DB_VERSION)"
else
    fail "MariaDB BUKAN versi 12.3.x ($DB_VERSION)"
fi

# local_infile=0
LOCAL_INFILE=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT @@local_infile;" 2>/dev/null | tr -d '\r')
if [ "$LOCAL_INFILE" = "0" ]; then
    pass "local_infile = 0"
else
    fail "local_infile bukan 0 ($LOCAL_INFILE)"
fi

# mariadbd berjalan sebagai dedicated user 'mysql'
MARIADB_USER=$(docker compose exec db ps aux | grep -E '[m]ariadbd|[m]ysqld' | awk '{print $1}' | head -n 1 | tr -d '\r')
if [ "$MARIADB_USER" = "mysql" ]; then
    pass "mariadbd berjalan sebagai dedicated user 'mysql' ($MARIADB_USER)"
else
    fail "mariadbd TIDAK berjalan sebagai user mysql ($MARIADB_USER)"
fi

# User anonim tidak ada
ANON_USERS=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT COUNT(*) FROM mysql.user WHERE User = '';" 2>/dev/null | tr -d '\r')
if [ "$ANON_USERS" = "0" ]; then
    pass "Tidak ada user anonim pada MariaDB"
else
    fail "Ditemukan $ANON_USERS user anonim"
fi

# User root HANYA diizinkan dari localhost/127.0.0.1/::1
ROOT_HOSTS=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SELECT host FROM mysql.user WHERE user='root';" 2>/dev/null | tr '\n' ' ' | tr -d '\r')
ROOT_HOSTS_TRIM=$(echo "$ROOT_HOSTS" | xargs)
if [[ "$ROOT_HOSTS" =~ ^(localhost|127\.0\.0\.1|::1|[[:space:]])+$ ]] && [ -n "$ROOT_HOSTS_TRIM" ]; then
    pass "User root MariaDB dibatasi HANYA pada host lokal ($ROOT_HOSTS_TRIM)"
else
    fail "User root MariaDB memiliki host yang tidak aman ($ROOT_HOSTS_TRIM)"
fi

# erp_app TIDAK bisa CREATE TABLE (F4: via MYSQL_PWD)
APP_CREATE_RES=$(docker compose exec -e MYSQL_PWD="${DB_APP_PASSWORD}" db mariadb -u "${DB_APP_USERNAME}" -e "CREATE TABLE erp_trading.probe_app (id INT);" 2>&1 || true)
if echo "$APP_CREATE_RES" | grep -Eiq "denied|access denied"; then
    pass "erp_app DITOLAK saat melakukan CREATE TABLE (Command denied to user)"
else
    fail "erp_app SEHARUSNYA TIDAK BISA CREATE TABLE ($APP_CREATE_RES)"
fi

# erp_migrator bisa DDL & DML (F4: via MYSQL_PWD)
if docker compose exec -e MYSQL_PWD="${DB_MIGRATOR_PASSWORD}" db mariadb -u "${DB_MIGRATOR_USERNAME}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
    CREATE TABLE erp_trading.probe_migrator (id INT PRIMARY KEY, name VARCHAR(50));
" >/dev/null 2>&1; then
    pass "erp_migrator BERHASIL membuat tabel migrasi (DDL)"
else
    fail "erp_migrator GAGAL membuat tabel migrasi (DDL)"
fi

# erp_app bisa DML pada tabel tersebut (F4: via MYSQL_PWD)
if docker compose exec -e MYSQL_PWD="${DB_APP_PASSWORD}" db mariadb -u "${DB_APP_USERNAME}" -e "
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
docker compose exec -e MYSQL_PWD="${DB_MIGRATOR_PASSWORD}" db mariadb -u "${DB_MIGRATOR_USERNAME}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
" >/dev/null 2>&1 || true

# erp_app tidak punya FILE privilege dan hanya DML
GRANTS_APP=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SHOW GRANTS FOR '${DB_APP_USERNAME}'@'%';" 2>/dev/null || true)
if echo "$GRANTS_APP" | grep -qiE "FILE|SUPER|PROCESS|GRANT OPTION|TRIGGER|CREATE|DROP|ALTER"; then
    fail "erp_app memiliki privilege terlarang (harus strictly DML)"
else
    pass "erp_app strictly DML dan TIDAK memiliki privilege FILE, SUPER, PROCESS, TRIGGER, atau DDL"
fi

# erp_migrator memiliki TRIGGER dan tidak memiliki SUPER, FILE, PROCESS, GRANT OPTION
GRANTS_MIGRATOR=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SHOW GRANTS FOR '${DB_MIGRATOR_USERNAME}'@'%';" 2>/dev/null || true)
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
for svc in web php db node; do
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
PHP_ENV=$(docker compose exec php env 2>/dev/null || true)
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
    if docker compose exec php test -e "/var/www/html/${forbidden}"; then
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
DOMAIN_TABLES_COUNT=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
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
FK_RULES=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT DELETE_RULE, COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'erp_trading'
    GROUP BY DELETE_RULE
    ORDER BY DELETE_RULE;
" 2>/dev/null)

CASCADE_COUNT=$(echo "$FK_RULES" | awk '$1=="CASCADE" {print $2}')
RESTRICT_COUNT=$(echo "$FK_RULES" | awk '$1=="RESTRICT" {print $2}')
OTHER_FK_RULES=$(echo "$FK_RULES" | grep -vE "^(CASCADE|RESTRICT)" || true)

NON_PIVOT_CASCADE=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
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
TRIGGERS_OUTPUT=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
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
CLI_ARGON=$(docker compose exec php php -r 'echo in_array("argon2id", password_algos()) ? "OK" : "MISSING";' 2>/dev/null || true)

PROBE_AUTH_FILE="/tmp/probe_auth_$$.php"
docker compose exec php sh -c "cat << 'EOF' > $PROBE_AUTH_FILE
<?php
echo in_array('argon2id', password_algos()) ? 'OK' : 'MISSING';
EOF"
FPM_ARGON=$(docker compose exec php sh -c "SCRIPT_FILENAME=$PROBE_AUTH_FILE REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000" 2>/dev/null || true)
docker compose exec php rm -f "$PROBE_AUTH_FILE" 2>/dev/null || true

if [ "$CLI_ARGON" = "OK" ] && echo "$FPM_ARGON" | grep -q "OK"; then
    pass "password_algos() memuat argon2id pada PHP CLI dan PHP-FPM"
else
    fail "password_algos() TIDAK memuat argon2id pada CLI ($CLI_ARGON) atau FPM ($FPM_ARGON)"
fi

# 7. Tidak ada user dengan email default yang dikenal di database erp_trading
DEFAULT_USERS_COUNT=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT COUNT(*) FROM erp_trading.users
    WHERE email IN ('admin@example.com', 'admin@erp.local', 'admin@erp.com', 'root@localhost', 'user@example.com', 'test@example.com', 'superadmin@example.com')
       OR email LIKE '%@example.com';
" 2>/dev/null | tr -d '[:space:]')

TOTAL_USERS_COUNT=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "
    SELECT COUNT(*) FROM erp_trading.users;
" 2>/dev/null | tr -d '[:space:]')

if [ "$DEFAULT_USERS_COUNT" = "0" ]; then
    pass "Tidak ada akun pengguna default/dummy di database erp_trading (default=0, total=$TOTAL_USERS_COUNT)"
else
    fail "Ditemukan akun pengguna default di database erp_trading (ditemukan $DEFAULT_USERS_COUNT)!"
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

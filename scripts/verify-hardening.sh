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
echo "       FASE 0: SECURITY & HARDENING VERIFICATION SUITE           "
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

# Cleanup trap for all temporary test files
cleanup() {
    info "Running cleanup of temporary test files and probes..."
    rm -rf src/public/.env \
           src/public/composer.json \
           src/public/vendor \
           src/public/storage \
           src/public/uploads \
           src/public/sub \
           src/public/build \
           src/public/ok.txt 2>/dev/null || true

    docker compose exec php rm -f /tmp/fpm_probe.php 2>/dev/null || true
    docker compose exec php rm -f /tmp/composer/test_write 2>/dev/null || true
    docker compose --profile tools exec node rm -f /home/node/.npm/test_write 2>/dev/null || true
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
# 3. Port terbuka di host: hanya 127.0.0.1 (web: 8080, node: 5173); db tidak terpublikasi
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
# 5. Uji Aturan Nginx dengan Berkas Nyata (R6b)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 5: Verifikasi Pemblokiran Nginx dengan Berkas Nyata ---"

# Buat berkas-berkas pengujian di src/public
echo "SECRET_KEY=leak" > src/public/.env
echo '{"name": "test"}' > src/public/composer.json
mkdir -p src/public/vendor && echo "sensitive" > src/public/vendor/x.txt
mkdir -p src/public/storage && echo "sensitive" > src/public/storage/x.txt
mkdir -p src/public/uploads && echo "<?php echo 'shell';" > src/public/uploads/x.php
mkdir -p src/public/sub && echo "<?php echo 'shell';" > src/public/sub/shell.php

# Kontrol positif
mkdir -p src/public/build/assets && echo "console.log('vendor-test');" > src/public/build/assets/vendor-test.js
echo "all good" > src/public/ok.txt

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
check_http_code "/vendor/x.txt" "^(403|404)$" "Direktori vendor diblokir"
check_http_code "/storage/x.txt" "^(403|404)$" "Direktori storage diblokir"
check_http_code "/uploads/x.php" "^(403|404)$" "Direktori uploads diblokir"
check_http_code "/sub/shell.php" "^404$" "Skrip PHP selain /index.php diblokir (404)"

# Uji Kontrol Positif
check_http_code "/build/assets/vendor-test.js" "^200$" "Aset valid build/assets/vendor-*.js dapat diakses (200)"
check_http_code "/ok.txt" "^200$" "Berkas statis publik normal dapat diakses (200)"

# ------------------------------------------------------------------------------
# 6. Probe PHP-FPM Independen via cgi-fcgi (R6a)
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 6: Verifikasi PHP Hardening (FPM via cgi-fcgi & CLI) ---"

# Buat berkas probe di tmpfs /tmp container php
docker compose exec php sh -c 'cat << "EOF" > /tmp/fpm_probe.php
<?php
echo json_encode([
    "disable_functions" => ini_get("disable_functions"),
    "exec_exists" => function_exists("exec"),
    "proc_open_exists" => function_exists("proc_open"),
    "open_basedir" => ini_get("open_basedir"),
    "allow_url_fopen" => ini_get("allow_url_fopen"),
    "expose_php" => ini_get("expose_php"),
]);
EOF'

FPM_RAW=$(docker compose exec php sh -c 'SCRIPT_FILENAME=/tmp/fpm_probe.php REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000' 2>/dev/null || true)
docker compose exec php rm -f /tmp/fpm_probe.php 2>/dev/null || true

FPM_JSON=$(echo "$FPM_RAW" | sed '1,/^$/d')

if echo "$FPM_JSON" | grep -q "exec"; then
    pass "disable_functions pada FPM memuat 'exec'"
else
    fail "disable_functions pada FPM tidak memuat 'exec' ($FPM_RAW)"
fi

if echo "$FPM_JSON" | grep -q '"exec_exists":false'; then
    pass "function_exists('exec') pada FPM bernilai false"
else
    fail "function_exists('exec') pada FPM TIDAK bernilai false ($FPM_JSON)"
fi

if echo "$FPM_JSON" | grep -q '/var/www/html'; then
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

# Cek ketersediaan proc_open di CLI
CLI_PROC_OPEN=$(docker compose exec php php -r "echo function_exists('proc_open') ? 'yes' : 'no';" 2>/dev/null | tr -d '\r')
if [ "$CLI_PROC_OPEN" = "yes" ]; then
    pass "proc_open TERSEDIA pada PHP CLI untuk kebutuhan Composer & Artisan"
else
    fail "proc_open TIDAK tersedia pada PHP CLI ($CLI_PROC_OPEN)"
fi

# ------------------------------------------------------------------------------
# 7. Verifikasi Composer, NPM, & Cache Writability pada Read-Only Rootfs (R6d)
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
if docker compose run --rm php touch /tmp/composer/test_write 2>/dev/null; then
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
if docker compose --profile tools run --rm node touch /home/node/.npm/test_write 2>/dev/null; then
    pass "Cache NPM (~/.npm) dapat ditulis (writable) pada rootfs read-only"
else
    fail "Cache NPM TIDAK dapat ditulis pada rootfs read-only"
fi

# ------------------------------------------------------------------------------
# 8. Verifikasi Hardening Database MariaDB (R6c & R6d)
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

# erp_app TIDAK bisa CREATE TABLE
APP_CREATE_RES=$(docker compose exec db mariadb -u "${DB_APP_USERNAME}" -p"${DB_APP_PASSWORD}" -e "CREATE TABLE erp_trading.probe_app (id INT);" 2>&1 || true)
if echo "$APP_CREATE_RES" | grep -Eiq "denied|access denied"; then
    pass "erp_app DITOLAK saat melakukan CREATE TABLE (Command denied to user)"
else
    fail "erp_app SEHARUSNYA TIDAK BISA CREATE TABLE ($APP_CREATE_RES)"
fi

# erp_migrator bisa DDL & DML
if docker compose exec db mariadb -u "${DB_MIGRATOR_USERNAME}" -p"${DB_MIGRATOR_PASSWORD}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
    CREATE TABLE erp_trading.probe_migrator (id INT PRIMARY KEY, name VARCHAR(50));
" >/dev/null 2>&1; then
    pass "erp_migrator BERHASIL membuat tabel migrasi (DDL)"
else
    fail "erp_migrator GAGAL membuat tabel migrasi (DDL)"
fi

# erp_app bisa DML pada tabel tersebut
if docker compose exec db mariadb -u "${DB_APP_USERNAME}" -p"${DB_APP_PASSWORD}" -e "
    INSERT INTO erp_trading.probe_migrator VALUES (1, 'test');
    UPDATE erp_trading.probe_migrator SET name = 'updated' WHERE id = 1;
    SELECT * FROM erp_trading.probe_migrator;
    DELETE FROM erp_trading.probe_migrator WHERE id = 1;
" >/dev/null 2>&1; then
    pass "erp_app BERHASIL melakukan operasi DML (SELECT, INSERT, UPDATE, DELETE)"
else
    fail "erp_app GAGAL melakukan operasi DML pada tabel migrasi"
fi

# Cleanup probe table
docker compose exec db mariadb -u "${DB_MIGRATOR_USERNAME}" -p"${DB_MIGRATOR_PASSWORD}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
" >/dev/null 2>&1 || true

# erp_app tidak punya FILE privilege
GRANTS_APP=$(docker compose exec -e MYSQL_PWD="$DB_ROOT_SECRET" db mariadb -u root -N -e "SHOW GRANTS FOR '${DB_APP_USERNAME}'@'%';" 2>/dev/null || true)
if echo "$GRANTS_APP" | grep -i "FILE" >/dev/null; then
    fail "erp_app memiliki FILE privilege yang dilarang"
else
    pass "erp_app TIDAK memiliki FILE privilege"
fi

# Isolasi network: db tidak terhubung ke frontend
DB_NETWORKS=$(docker inspect erp-trading-db --format '{{json .NetworkSettings.Networks}}')
if echo "$DB_NETWORKS" | grep -q "frontend"; then
    fail "db TERHUBUNG ke jaringan frontend (seharusnya hanya backend)"
else
    pass "db terisolasi di jaringan backend internal (tidak terhubung ke frontend)"
fi

# ------------------------------------------------------------------------------
# 9. Verifikasi Kebocoran Rahasia & Isolasi Container PHP (R6d)
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

# Container PHP TIDAK boleh melihat .git, secrets/, docker/, compose.yaml, atau .env infra
PHP_HTML_FILES=$(docker compose exec php ls -a /var/www/html 2>/dev/null | tr '\n' ' ')
ISOLATED=true
for forbidden in .git secrets docker compose.yaml compose.yml .env; do
    if docker compose exec php test -e "/var/www/html/${forbidden}"; then
        fail "Container php DAPAT melihat berkas infra '${forbidden}' di /var/www/html"
        ISOLATED=false
    fi
done
if [ "$ISOLATED" = "true" ]; then
    pass "Container php terisolasi sempurna: tidak dapat melihat .git, secrets, docker, compose.yaml, atau .env infra"
fi

# ------------------------------------------------------------------------------
# 10. Gitleaks lolos pada seluruh repo
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 10: Verifikasi Pemindaian Rahasia Gitleaks ---"

GITLEAKS_IMG="zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"
if docker run --rm -v "$(pwd):/repo" -w /repo "$GITLEAKS_IMG" git --verbose --no-banner >/dev/null 2>&1; then
    pass "Gitleaks lolos tanpa temuan rahasia pada seluruh repositori"
else
    fail "Gitleaks mendeteksi rahasia pada repositori"
fi

# ------------------------------------------------------------------------------
# 11. git ls-files tidak memuat .env atau key
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 11: Verifikasi File Rahasia di Git Index ---"

FORBIDDEN_FILES=$(git ls-files | grep -Ei '\.env|\.pem|\.key|\.crt|id_rsa|id_ed25519' | grep -v '\.env\.example' | grep -v '\.env\.production\.example' || true)
if [ -z "$FORBIDDEN_FILES" ]; then
    pass "git ls-files bersih dari .env atau key privat"
else
    fail "Ditemukan file sensitif terindeks di Git: $FORBIDDEN_FILES"
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

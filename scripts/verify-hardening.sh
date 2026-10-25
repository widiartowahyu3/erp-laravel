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

# Ensure temporary probe files exist for HTTP verification during verification
CLEANUP_PROBE=false
if [ ! -f src/public/index.php ]; then
    CLEANUP_PROBE=true
    cat << 'EOF' > src/public/index.php
<?php
if (isset($_GET['probe']) && $_GET['probe'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'disable_functions' => ini_get('disable_functions'),
        'exec_exists' => function_exists('exec'),
        'proc_open_exists' => function_exists('proc_open'),
        'php_sapi' => PHP_SAPI,
        'expose_php' => ini_get('expose_php'),
    ], JSON_PRETTY_PRINT);
    exit;
}
echo "ok\n";
EOF
fi

if [ ! -f src/public/other.php ]; then
    cat << 'EOF' > src/public/other.php
<?php
echo "forbidden";
EOF
fi

cleanup() {
    if [ "$CLEANUP_PROBE" = "true" ]; then
        rm -f src/public/index.php src/public/other.php
    fi
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

# Ensure stack is up
info "Checking Docker Compose services status..."
if ! docker compose ps --services --filter "status=running" | grep -q "web"; then
    info "Starting containers for verification..."
    docker compose up -d
fi

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
NODE_UID=$(docker compose --profile tools run --rm --no-deps node id -u 2>/dev/null | tr -d '\r')

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

for svc in web php db; do
    container_name="erp-trading-${svc}"
    ro=$(docker inspect "$container_name" --format '{{.HostConfig.ReadonlyRootfs}}')
    nnp=$(docker inspect "$container_name" --format '{{json .HostConfig.SecurityOpt}}')
    caps_drop=$(docker inspect "$container_name" --format '{{json .HostConfig.CapDrop}}')
    caps_add=$(docker inspect "$container_name" --format '{{json .HostConfig.CapAdd}}')

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
# 3. Port terbuka di host hanya 127.0.0.1:8080; db tidak terpublikasi
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 3: Verifikasi Port Binding Host ---"

WEB_PORTS=$(docker inspect erp-trading-web --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}')
DB_PORTS=$(docker inspect erp-trading-db --format '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}} {{end}}{{end}}')

if [[ "$WEB_PORTS" =~ ^127\.0\.0\.1:8080[[:space:]]*$ ]]; then
    pass "Port web hanya terikat ke 127.0.0.1:8080 ($WEB_PORTS)"
else
    fail "Port web terikat tidak aman ($WEB_PORTS)"
fi

if [ -z "$DB_PORTS" ]; then
    pass "Port MariaDB TIDAK dipublikasikan ke host (DB_PORTS kosong)"
else
    fail "Port MariaDB terpublikasi ke host: $DB_PORTS"
fi

# ------------------------------------------------------------------------------
# 4. Respons header tidak memuat X-Powered-By dan versi Nginx
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 4: Verifikasi Kebocoran Informasi pada Header HTTP ---"

HEADERS=$(curl -sI http://127.0.0.1:8080/ || true)

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
# 5. /.env, /.git/config, /composer.json, /vendor/, /storage/ dan /other.php -> 403/404
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 5: Verifikasi Pemblokiran File Sensitif & Eksekusi PHP Ilegal ---"

check_http_code() {
    local path="$1"
    local expected="$2"
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8080${path}")
    if [[ "$code" =~ $expected ]]; then
        pass "Akses ke $path mengembalikan HTTP $code (sesuai ekspektasi $expected)"
    else
        fail "Akses ke $path mengembalikan HTTP $code (seharusnya $expected)"
    fi
}

check_http_code "/.env" "^(403|404)$"
check_http_code "/.git/config" "^(403|404)$"
check_http_code "/composer.json" "^(403|404)$"
check_http_code "/vendor/" "^(403|404)$"
check_http_code "/storage/" "^(403|404)$"
check_http_code "/other.php" "^404$"

# ------------------------------------------------------------------------------
# 6. /index.php?probe=1: disable_functions aktif, exec false, expose_php off.
#    Dari CLI: proc_open tersedia.
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 6: Verifikasi PHP Hardening (FPM vs CLI) ---"

PROBE_RESP=$(curl -s "http://127.0.0.1:8080/index.php?probe=1" || true)

if echo "$PROBE_RESP" | grep -q "exec"; then
    pass "disable_functions pada FPM memuat exec"
else
    fail "disable_functions pada FPM tidak memuat exec ($PROBE_RESP)"
fi

if echo "$PROBE_RESP" | grep -q '"exec_exists": false'; then
    pass "function_exists('exec') pada FPM bernilai false"
else
    fail "function_exists('exec') pada FPM TIDAK false ($PROBE_RESP)"
fi

if echo "$PROBE_RESP" | grep -E '"expose_php": (""|"0"|false)' >/dev/null; then
    pass "expose_php pada FPM berstatus Off"
else
    fail "expose_php pada FPM belum Off ($PROBE_RESP)"
fi

CLI_PROC_OPEN=$(docker compose exec php php -r "echo function_exists('proc_open') ? 'yes' : 'no';" 2>/dev/null | tr -d '\r')
if [ "$CLI_PROC_OPEN" = "yes" ]; then
    pass "proc_open TERSEDIA pada PHP CLI untuk kebutuhan Composer & Artisan"
else
    fail "proc_open TIDAK tersedia pada PHP CLI ($CLI_PROC_OPEN)"
fi

# ------------------------------------------------------------------------------
# 7. composer --version berjalan di container php sebagai non-root
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 7: Verifikasi Composer CLI Non-Root ---"

COMPOSER_VER=$(docker compose exec php composer --version 2>/dev/null | tr -d '\r')
if [[ "$COMPOSER_VER" =~ Composer[[:space:]]version ]]; then
    pass "composer --version berjalan sukses di container php: $COMPOSER_VER"
else
    fail "composer --version gagal dijalankan ($COMPOSER_VER)"
fi

# ------------------------------------------------------------------------------
# 8. MariaDB: 12.3.x, local_infile=0, user mysql, erp_app vs erp_migrator,
#    tanpa FILE privilege, tanpa anonim, isolasi network
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 8: Verifikasi Hardening Database MariaDB ---"

# Versi 12.3.x
DB_VERSION=$(docker compose exec db mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e "SELECT VERSION();" 2>/dev/null | tr -d '\r')
if [[ "$DB_VERSION" =~ ^12\.3\. ]]; then
    pass "MariaDB versi 12.3.x ($DB_VERSION)"
else
    fail "MariaDB BUKAN versi 12.3.x ($DB_VERSION)"
fi

# local_infile=0
LOCAL_INFILE=$(docker compose exec db mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e "SELECT @@local_infile;" 2>/dev/null | tr -d '\r')
if [ "$LOCAL_INFILE" = "0" ]; then
    pass "local_infile = 0"
else
    fail "local_infile bukan 0 ($LOCAL_INFILE)"
fi

# Proses mariadbd berjalan sebagai user mysql
MARIADB_USER=$(docker compose exec db ps aux | grep -E '[m]ariadbd|[m]ysqld' | awk '{print $1}' | head -n 1 | tr -d '\r')
if [ "$MARIADB_USER" = "mysql" ]; then
    pass "mariadbd berjalan sebagai dedicated user 'mysql' ($MARIADB_USER)"
else
    fail "mariadbd TIDAK berjalan sebagai user mysql ($MARIADB_USER)"
fi

# User anonim tidak ada
ANON_USERS=$(docker compose exec db mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e "SELECT COUNT(*) FROM mysql.user WHERE User = '';" 2>/dev/null | tr -d '\r')
if [ "$ANON_USERS" = "0" ]; then
    pass "Tidak ada user anonim pada MariaDB"
else
    fail "Ditemukan $ANON_USERS user anonim"
fi

# erp_app TIDAK bisa CREATE TABLE
APP_CREATE_RES=$(docker compose exec db mariadb -u "${DB_APP_USERNAME}" -p"${DB_APP_PASSWORD}" -e "CREATE TABLE erp_trading.probe_app (id INT);" 2>&1 || true)
if echo "$APP_CREATE_RES" | grep -Eiq "denied|access denied"; then
    pass "erp_app DITOLAK saat melakukan CREATE TABLE (Command denied to user)"
else
    fail "erp_app SEHARUSNYA TIDAK BISA CREATE TABLE ($APP_CREATE_RES)"
fi

# erp_migrator bisa DDL & DML
docker compose exec db mariadb -u "${DB_MIGRATOR_USERNAME}" -p"${DB_MIGRATOR_PASSWORD}" -e "
    DROP TABLE IF EXISTS erp_trading.probe_migrator;
    CREATE TABLE erp_trading.probe_migrator (id INT PRIMARY KEY, name VARCHAR(50));
" >/dev/null 2>&1
pass "erp_migrator BERHASIL membuat tabel migrasi"

# erp_app bisa DML pada tabel tersebut
docker compose exec db mariadb -u "${DB_APP_USERNAME}" -p"${DB_APP_PASSWORD}" -e "
    INSERT INTO erp_trading.probe_migrator VALUES (1, 'test');
    UPDATE erp_trading.probe_migrator SET name = 'updated' WHERE id = 1;
    SELECT * FROM erp_trading.probe_migrator;
    DELETE FROM erp_trading.probe_migrator WHERE id = 1;
" >/dev/null 2>&1
pass "erp_app BERHASIL melakukan DML (SELECT, INSERT, UPDATE, DELETE)"

# Cleanup probe table
docker compose exec db mariadb -u "${DB_MIGRATOR_USERNAME}" -p"${DB_MIGRATOR_PASSWORD}" -e "
    DROP TABLE erp_trading.probe_migrator;
" >/dev/null 2>&1

# erp_app tidak punya FILE privilege
GRANTS_APP=$(docker compose exec db mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e "SHOW GRANTS FOR '${DB_APP_USERNAME}'@'%';" 2>/dev/null || true)
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
# 9. Gitleaks lolos pada seluruh repo
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 9: Verifikasi Pemindaian Rahasia Gitleaks ---"

GITLEAKS_IMG="zricethezav/gitleaks:v8.30.1"
if docker run --rm -v "$(pwd):/repo" -w /repo "$GITLEAKS_IMG" git --verbose --no-banner >/dev/null 2>&1; then
    pass "Gitleaks lolos tanpa temuan rahasia pada seluruh repositori"
else
    fail "Gitleaks mendeteksi rahasia pada repositori"
fi

# ------------------------------------------------------------------------------
# 10. git ls-files tidak memuat .env atau key
# ------------------------------------------------------------------------------
echo ""
echo "--- Butir 10: Verifikasi File Rahasia di Git Index ---"

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

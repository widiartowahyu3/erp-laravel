#!/usr/bin/env bash
set -uo pipefail

# ==============================================================================
# Uji asap image production: membangun image, menjalankan compose.prod.yaml pada
# project terpisah (database tmpfs, port loopback), lalu menguji deploy, HTTPS,
# login, dan hardening. Stack pengembangan tidak disentuh.
# ==============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."

TAG="smoke"
DOMAIN="smoke.erp.test"
HTTP_PORT=18080
HTTPS_PORT=18443
BASE="https://${DOMAIN}:${HTTPS_PORT}"
FAILURES=0

WORK_DIR="$(mktemp -d)"
chmod 755 "$WORK_DIR"
mkdir -p "$WORK_DIR/certs" "$WORK_DIR/acme" "$WORK_DIR/backups" "$WORK_DIR/state"
chmod 755 "$WORK_DIR/certs" "$WORK_DIR/acme"

export COMPOSE_PROJECT_NAME="erp-prod-smoke"
export COMPOSE_FILE="compose.prod.yaml:docker/smoke/compose.smoke.yaml"
export COMPOSE_ENV_FILES="$WORK_DIR/infra.env"
export ERP_IMAGE_TAG="$TAG"
export BACKUP_DIR="$WORK_DIR/backups"
export DEPLOY_ENV_FILE="$WORK_DIR/infra.env"
export DEPLOY_COMPOSE_FILE="$COMPOSE_FILE"
export DEPLOY_PROJECT_NAME="$COMPOSE_PROJECT_NAME"
export DEPLOY_STATE_DIR="$WORK_DIR/state"
export DEPLOY_SKIP_PULL=1

cleanup() {
    docker compose down --remove-orphans >/dev/null 2>&1
    find "$WORK_DIR" -type f -delete
    find "$WORK_DIR" -depth -type d -empty -delete
}
trap cleanup EXIT

check() {
    local name="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo "[PASS] $name"
    else
        echo "[FAIL] $name (diharapkan '$expected', didapat '$actual')"
        FAILURES=$((FAILURES + 1))
    fi
}

check_contains() {
    local name="$1" pattern="$2" haystack="$3"
    if echo "$haystack" | grep -qiE "$pattern"; then
        echo "[PASS] $name"
    else
        echo "[FAIL] $name (pola '$pattern' tidak ditemukan)"
        FAILURES=$((FAILURES + 1))
    fi
}

check_absent() {
    local name="$1" pattern="$2" haystack="$3"
    if echo "$haystack" | grep -qiE "$pattern"; then
        echo "[FAIL] $name (pola '$pattern' ditemukan)"
        FAILURES=$((FAILURES + 1))
    else
        echo "[PASS] $name"
    fi
}

web() {
    curl -sk -m 30 --resolve "${DOMAIN}:${HTTPS_PORT}:127.0.0.1" --resolve "${DOMAIN}:${HTTP_PORT}:127.0.0.1" "$@"
}

status_of() {
    web -o /dev/null -w '%{http_code}' "$@"
}

echo "==> Memeriksa pin image"
bash scripts/check-image-pins.sh || exit 1

echo "==> Membangun image production"
docker build -q -f docker/php/Dockerfile --target prod -t "erp-smoke-php:${TAG}" . || exit 1
docker build -q -f docker/php/Dockerfile --target web -t "erp-smoke-web:${TAG}" . || exit 1

echo "==> Menyiapkan rahasia sementara"
DB_APP_PW="$(openssl rand -hex 24)"
openssl rand -hex 24 > "$WORK_DIR/db_root_password.txt"
chmod 644 "$WORK_DIR/db_root_password.txt"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=${DOMAIN}" \
    -keyout "$WORK_DIR/certs/privkey.pem" -out "$WORK_DIR/certs/fullchain.pem" >/dev/null 2>&1
chmod 644 "$WORK_DIR/certs/privkey.pem" "$WORK_DIR/certs/fullchain.pem"

cat > "$WORK_DIR/infra.env" <<ENV
APP_DOMAIN=${DOMAIN}
ERP_PHP_IMAGE=erp-smoke-php
ERP_WEB_IMAGE=erp-smoke-web
BIND_ADDR=127.0.0.1
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}
TLS_CERT_DIR=${WORK_DIR}/certs
ACME_WEBROOT=${WORK_DIR}/acme
APP_ENV_FILE=${WORK_DIR}/app.env
DB_ROOT_PASSWORD_FILE=${WORK_DIR}/db_root_password.txt
DB_APP_USERNAME=erp_app
DB_MIGRATOR_USERNAME=erp_migrator
ENV
printf 'DB_APP_PASSWORD=%s\nDB_MIGRATOR_PASSWORD=%s\n' "$DB_APP_PW" "$(openssl rand -hex 24)" >> "$WORK_DIR/infra.env"

sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_APP_PW}|" \
    src/.env.production.example > "$WORK_DIR/app.env"

echo "==> Deploy pertama"
if ! bash scripts/deploy.sh "$TAG"; then
    docker compose logs --no-color --tail=60 web php scheduler
    exit 1
fi
check "Versi aktif tercatat setelah deploy" "$TAG" "$(cat "$WORK_DIR/state/current-tag")"

docker compose run --rm -T --no-deps php php artisan db:seed --force </dev/null >/dev/null 2>&1
check "Seeder referensi berjalan di production" "0" "$?"

echo "==> HTTPS dan header"
check "HTTP dialihkan ke HTTPS" "301" "$(status_of "http://${DOMAIN}:${HTTP_PORT}/login")"
check "Endpoint /up" "200" "$(status_of "${BASE}/up")"
check "Host tidak dikenal ditolak" "400" "$(status_of -H 'Host: evil.example' "${BASE}/login")"

LOGIN_HEADERS="$(web -D - -o /dev/null "${BASE}/login")"
check_contains "Halaman login 200" "^HTTP/[0-9.]+ 200" "$LOGIN_HEADERS"
check_contains "HSTS aktif" "^strict-transport-security: max-age=31536000" "$LOGIN_HEADERS"
check_contains "CSP aktif" "^content-security-policy: default-src 'self'" "$LOGIN_HEADERS"
check_contains "Cookie sesi Secure dan HttpOnly" "^set-cookie: [a-z0-9_-]*session=.*secure.*httponly" "$LOGIN_HEADERS"
check_absent "Versi PHP tidak bocor" "^x-powered-by" "$LOGIN_HEADERS"
check_absent "Versi nginx tidak bocor" "^server: nginx/[0-9]" "$LOGIN_HEADERS"

NOT_FOUND_BODY="$(web "${BASE}/halaman-tidak-ada")"
check_absent "Halaman 404 tanpa jejak internal" "/var/www/html|Stack trace|vendor/laravel" "$NOT_FOUND_BODY"

echo "==> Jalur sensitif"
for path in /login/ /index.php/login /.env /composer.json /storage/logs/laravel.log /vendor/autoload.php /artisan; do
    check "Jalur ${path} tertutup" "404" "$(status_of -X POST "${BASE}${path}")"
done

echo "==> Login menyeluruh"
SMOKE_PASS="$(openssl rand -hex 16)Aa1"
SMOKE_HASH="$(docker compose exec -T -e SMOKE_PASS="$SMOKE_PASS" php php -r \
    'echo password_hash(getenv("SMOKE_PASS"), PASSWORD_ARGON2ID, ["memory_cost" => 65536, "time_cost" => 4, "threads" => 1]);')"
docker compose exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root erp_trading' <<SQL
INSERT INTO users (name, email, password, is_active, created_at, updated_at)
VALUES ('Smoke', 'smoke@example.test', '${SMOKE_HASH}', 1, NOW(), NOW());
SQL

JAR="$WORK_DIR/cookies.txt"
web -c "$JAR" -b "$JAR" -o /dev/null "${BASE}/login"
XSRF="$(grep 'XSRF-TOKEN' "$JAR" | awk '{print $NF}')"
XSRF_DECODED="$(printf '%b' "${XSRF//%/\\x}")"

check "Login tanpa CSRF ditolak" "419" "$(status_of -d 'email=smoke@example.test&password=salah' "${BASE}/login")"
check "Kata sandi salah tidak masuk" "302" "$(status_of -c "$JAR" -b "$JAR" -H "X-XSRF-TOKEN: ${XSRF_DECODED}" \
    -d 'email=smoke@example.test&password=salah' "${BASE}/login")"
check "Dashboard masih menolak tamu" "302" "$(status_of -c "$JAR" -b "$JAR" "${BASE}/")"

XSRF="$(grep 'XSRF-TOKEN' "$JAR" | awk '{print $NF}')"
XSRF_DECODED="$(printf '%b' "${XSRF//%/\\x}")"
check "Kata sandi benar diterima" "302" "$(status_of -c "$JAR" -b "$JAR" -H "X-XSRF-TOKEN: ${XSRF_DECODED}" \
    -d "email=smoke@example.test&password=${SMOKE_PASS}" "${BASE}/login")"
check "Dashboard terbuka setelah login" "200" "$(status_of -c "$JAR" -b "$JAR" "${BASE}/")"

echo "==> Hardening container"
PHP_ENV="$(docker compose exec -T php env)"
check_absent "Kredensial migrator tidak ada di container php" "MIGRATOR" "$PHP_ENV"
check_absent "Kata sandi root tidak ada di container php" "ROOT_PASSWORD" "$PHP_ENV"
check "Proses php bukan root" "1000" "$(docker compose exec -T php id -u | tr -d '\r')"
check "Proses web bukan root" "101" "$(docker compose exec -T web id -u | tr -d '\r')"
docker compose exec -T php touch /var/www/html/public/smoke-write >/dev/null 2>&1
check "Kode aplikasi tidak bisa ditulis" "1" "$?"
docker compose exec -T php php -r 'exit(@fsockopen("1.1.1.1", 443, $e, $s, 3) ? 0 : 1);' >/dev/null 2>&1
check "Container php tanpa akses internet" "1" "$?"
for forbidden in /usr/bin/composer /var/www/html/.env /var/www/html/tests /var/www/html/node_modules /var/www/html/vendor/pestphp; do
    docker compose exec -T php test ! -e "$forbidden"
    check "Image php tidak memuat ${forbidden}" "0" "$?"
done
for needed in /var/www/html/vendor/autoload.php /var/www/html/public/build/manifest.json; do
    docker compose exec -T php test -f "$needed"
    check "Image php memuat ${needed}" "0" "$?"
done

echo "==> Penjadwal"
SCHEDULER_ENV="$(docker compose exec -T scheduler env)"
check_absent "Kredensial migrator tidak ada di container scheduler" "MIGRATOR" "$SCHEDULER_ENV"
check_absent "Kata sandi root tidak ada di container scheduler" "ROOT_PASSWORD" "$SCHEDULER_ENV"
check "Proses scheduler bukan root" "1000" "$(docker compose exec -T scheduler id -u | tr -d '\r')"
docker compose exec -T scheduler php -r 'exit(@fsockopen("1.1.1.1", 443, $e, $s, 3) ? 0 : 1);' >/dev/null 2>&1
check "Container scheduler tanpa akses internet" "1" "$?"
docker compose exec -T scheduler php -r 'exit(@fsockopen("web", 8443, $e, $s, 3) ? 0 : 1);' >/dev/null 2>&1
check "Container scheduler tidak terhubung ke web" "1" "$?"
check_contains "Penghapusan log audit terjadwal" "erp:audit:prune" "$(docker compose exec -T scheduler php artisan schedule:list)"
docker compose exec -T scheduler php artisan erp:audit:prune >/dev/null 2>&1
check "Perintah penghapusan log audit berjalan" "0" "$?"

echo "==> Deploy ulang dengan backup"
bash scripts/deploy.sh "$TAG" >/dev/null 2>&1
check "Deploy kedua berhasil" "0" "$?"
check "Backup dibuat sebelum deploy kedua" "1" "$(find "$WORK_DIR/backups" -name '*.sql.gz' -size +0 | wc -l | tr -d ' ')"

echo "==> Rollback saat uji kesehatan gagal"
DEPLOY_HEALTH_PATH=/halaman-tidak-ada bash scripts/deploy.sh "$TAG" >/dev/null 2>&1
check "Deploy gagal dilaporkan dengan exit 1" "1" "$?"
check "Aplikasi tetap sehat setelah rollback" "200" "$(status_of "${BASE}/up")"
check "Versi aktif tidak berubah" "$TAG" "$(cat "$WORK_DIR/state/current-tag")"

echo "================================================================="
if [ "$FAILURES" -gt 0 ]; then
    echo "HASIL UJI ASAP: ${FAILURES} GAGAL"
    docker compose logs --no-color --tail=40 web php scheduler
    exit 1
fi
echo "HASIL UJI ASAP: SEMUA LULUS"

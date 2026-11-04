#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Deploy satu versi image ke host Docker production.
# Pemakaian: bash scripts/deploy.sh v1.2.3
#
# Urutan: tarik image -> backup -> migrasi -> ganti container -> uji /up.
# Bila uji gagal, container dikembalikan ke versi sebelumnya. Migrasi TIDAK ikut
# dibatalkan; pulihkan dari berkas backup bila skemanya tidak kompatibel.
# ==============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."

TAG="${1:-}"
if ! [[ "$TAG" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
    echo "[ERROR] Pemakaian: bash scripts/deploy.sh <tag-image>" >&2
    exit 2
fi

ENV_FILE="${DEPLOY_ENV_FILE:-.env.production}"
STATE_FILE="${DEPLOY_STATE_DIR:-.deploy}/current-tag"
HEALTH_PATH="${DEPLOY_HEALTH_PATH:-/up}"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] Berkas '$ENV_FILE' tidak ditemukan." >&2
    exit 2
fi

env_value() {
    grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2-
}

export COMPOSE_FILE="${DEPLOY_COMPOSE_FILE:-compose.prod.yaml}"
export COMPOSE_ENV_FILES="$ENV_FILE"
export COMPOSE_PROJECT_NAME="${DEPLOY_PROJECT_NAME:-erp-trading-prod}"
export ERP_IMAGE_TAG="$TAG"

DOMAIN="$(env_value APP_DOMAIN)"
PREVIOUS_TAG=""
if [ -f "$STATE_FILE" ]; then
    PREVIOUS_TAG="$(cat "$STATE_FILE")"
fi

health_check() {
    docker compose exec -T web curl -fsSk -o /dev/null -H "Host: ${DOMAIN}" "https://127.0.0.1:8443${HEALTH_PATH}"
}

echo "[INFO] Deploy versi '${TAG}' (sebelumnya: '${PREVIOUS_TAG:-tidak ada}')."

if [ "${DEPLOY_SKIP_PULL:-0}" != "1" ]; then
    docker compose pull web php
fi

docker compose up -d --wait --wait-timeout 180 db

if [ -n "$PREVIOUS_TAG" ]; then
    bash scripts/db-backup.sh
else
    echo "[INFO] Deploy pertama: backup dilewati."
fi

# shellcheck disable=SC2046
export $(grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' "$ENV_FILE" | xargs)

docker compose run --rm -T --no-deps \
    -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD \
    --entrypoint php php artisan migrate --database=mariadb_migrator --force < /dev/null

if docker compose up -d --wait --wait-timeout 180 web php && health_check; then
    mkdir -p "$(dirname "$STATE_FILE")"
    printf '%s\n' "$TAG" > "$STATE_FILE"
    echo "[PASS] Versi '${TAG}' aktif."
    exit 0
fi

echo "[ERROR] Versi '${TAG}' gagal uji kesehatan." >&2
if [ -n "$PREVIOUS_TAG" ]; then
    ERP_IMAGE_TAG="$PREVIOUS_TAG" docker compose up -d --wait --wait-timeout 180 web php
    echo "[INFO] Container dikembalikan ke '${PREVIOUS_TAG}'. Migrasi tidak dibatalkan." >&2
fi
exit 1

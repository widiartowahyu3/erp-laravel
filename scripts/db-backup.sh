#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Script Backup Database erp_trading
# Membaca root password dari /run/secrets/db_root_password di dalam container db
# Tidak pernah mencetak atau meneruskan kata sandi via CLI argument
# ==============================================================================

BACKUP_DIR="backups"
TIMESTAMP=$(date -u +%Y%m%d_%H%M%SZ)
BACKUP_FILE="${BACKUP_DIR}/erp_trading_${TIMESTAMP}.sql.gz"

# 1. Pastikan direktori backup memiliki hak akses ketat 700 dan umask ketat
umask 077
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

echo "[INFO] Memulai backup database 'erp_trading' ke '${BACKUP_FILE}'..."

# 2. Jalankan mariadb-dump via container db dengan flags aman dan kompresi gzip (dibuat langsung dengan mode 600 via umask 077)
timeout 120 docker compose exec -T db sh -c \
  'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb-dump -u root --single-transaction --routines --triggers --events erp_trading' < /dev/null | gzip -9 > "${BACKUP_FILE}"

# 3. Kunci hak akses berkas backup menjadi 600
chmod 600 "${BACKUP_FILE}"

# 4. Verifikasi berkas backup terbuat dan tidak kosong
if [ ! -s "${BACKUP_FILE}" ]; then
  echo "[ERROR] Berkas backup kosong atau gagal dibuat: ${BACKUP_FILE}" >&2
  exit 1
fi

FILE_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
echo "[PASS] Backup berhasil dibuat: ${BACKUP_FILE} (Ukuran: ${FILE_SIZE})"

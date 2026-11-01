#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Script Restore-Check Database
# Memulihkan backup terbaru ke database sementara: erp_trading_restore_check
# Memverifikasi jumlah baris, CHECKSUM TABLE untuk tabel non-volatil,
# dan 4 trigger append-only, kemudian membersihkan database sementara.
# ==============================================================================

TEMP_DB="erp_trading_restore_check"

# 1. Validasi nama database sementara (hanya izinkan erp_trading_restore_check)
if [ "${TEMP_DB}" != "erp_trading_restore_check" ]; then
  echo "[ERROR] Nama database restore check tidak sah: ${TEMP_DB}" >&2
  exit 1
fi

# 2. Cari berkas backup terbaru
LATEST_BACKUP=$(ls -t backups/erp_trading_*.sql.gz 2>/dev/null | head -n 1 || true)
if [ -z "${LATEST_BACKUP}" ] || [ ! -f "${LATEST_BACKUP}" ]; then
  echo "[ERROR] Tidak ditemukan berkas backup di backups/erp_trading_*.sql.gz" >&2
  exit 1
fi

echo "[INFO] Menemukan backup terbaru: ${LATEST_BACKUP}"

# 3. Buat database sementara erp_trading_restore_check
echo "[INFO] Menyiapkan database sementara '${TEMP_DB}'..."
timeout 30 docker compose exec -T db sh -c \
  'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root -e "DROP DATABASE IF EXISTS erp_trading_restore_check; CREATE DATABASE erp_trading_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"' < /dev/null

# 4. Muat berkas backup ke dalam database sementara
echo "[INFO] Memulihkan dump ke database '${TEMP_DB}'..."
gunzip -c "${LATEST_BACKUP}" | timeout 120 docker compose exec -T db sh -c \
  'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root erp_trading_restore_check'

# 5. Verifikasi perbandingan tabel dan checksum
echo "[INFO] Memverifikasi integritas baris dan CHECKSUM TABLE..."
timeout 60 docker compose exec -T db sh -c '
set -eu
export MYSQL_PWD="$(cat /run/secrets/db_root_password)"
TABLES=$(mariadb -u root -sNe "SHOW TABLES IN erp_trading")

for tbl in $TABLES; do
  case "$tbl" in
    sessions|cache|cache_locks|jobs|job_batches|failed_jobs)
      continue
      ;;
  esac

  CNT_SRC=$(mariadb -u root -sNe "SELECT COUNT(*) FROM erp_trading.$tbl")
  CNT_DST=$(mariadb -u root -sNe "SELECT COUNT(*) FROM erp_trading_restore_check.$tbl")
  if [ "$CNT_SRC" != "$CNT_DST" ]; then
    echo "[ERROR] Ketidakcocokan baris pada tabel $tbl: erp_trading ($CNT_SRC) vs restore ($CNT_DST)" >&2
    exit 1
  fi

  CS_SRC=$(mariadb -u root -sNe "CHECKSUM TABLE erp_trading.$tbl" | cut -f2)
  CS_DST=$(mariadb -u root -sNe "CHECKSUM TABLE erp_trading_restore_check.$tbl" | cut -f2)
  if [ "$CS_SRC" != "$CS_DST" ]; then
    echo "[ERROR] Ketidakcocokan checksum pada tabel $tbl: erp_trading ($CS_SRC) vs restore ($CS_DST)" >&2
    exit 1
  fi

  echo "  [PASS] $tbl: baris=$CNT_SRC, checksum=$CS_SRC"
done
' < /dev/null

# 6. Verifikasi 4 trigger append-only
echo "[INFO] Memverifikasi keberadaan 4 trigger append-only..."
TRIGGERS=$(timeout 30 docker compose exec -T db sh -c \
  'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root -sNe "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '\''erp_trading_restore_check'\'' ORDER BY TRIGGER_NAME"' < /dev/null)

EXPECTED_TRIGGERS="tr_audit_logs_before_delete
tr_audit_logs_before_update
tr_stock_movements_before_delete
tr_stock_movements_before_update"

if [ "${TRIGGERS}" != "${EXPECTED_TRIGGERS}" ]; then
  echo "[ERROR] Trigger tidak cocok!" >&2
  echo "Expected:" >&2
  echo "${EXPECTED_TRIGGERS}" >&2
  echo "Actual:" >&2
  echo "${TRIGGERS}" >&2
  exit 1
fi
echo "  [PASS] 4 trigger append-only terverifikasi ada."

# 7. Bersihkan database sementara (DROP DATABASE satu-satunya yang diizinkan fase ini)
echo "[INFO] Membersihkan database sementara '${TEMP_DB}'..."
timeout 30 docker compose exec -T db sh -c \
  'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root -e "DROP DATABASE erp_trading_restore_check;"' < /dev/null

echo "[PASS] Seluruh pengujian restore-check berhasil dan database sementara telah dihapus."

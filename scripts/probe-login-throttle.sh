#!/usr/bin/env bash
# scripts/probe-login-throttle.sh
# Pengujian probe ketahanan pembatas laju login terhadap spoofing X-Forwarded-For.
# Aman dijalankan oleh pemilik (menggunakan email acak per eksekusi).

set -euo pipefail

cd "$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

FAILED=0
APP_URL="${APP_URL:-http://127.0.0.1:8080}"

echo -e "${BLUE}=================================================================${NC}"
echo -e "${BLUE}       PROBE PEMBATAS LAJU LOGIN (ANTI-SPOOFING XFF)            ${NC}"
echo -e "${BLUE}=================================================================${NC}"

# ------------------------------------------------------------------------------
docker compose restart web >/dev/null 2>&1 || true
sleep 1

# Skenario A: 10 Percobaan dengan header X-Forwarded-For berbeda-beda
# Target: WAJIB mencapai HTTP 429 pada percobaan ke-6 atau lebih awal.
# ------------------------------------------------------------------------------
echo ""
docker compose restart web >/dev/null 2>&1 || true
sleep 1

echo "--- Skenario A: 10 Percobaan Gagal dengan X-Forwarded-For Berputar ---"

COOKIE_JAR_A=$(mktemp)
trap 'rm -f "$COOKIE_JAR_A" "${COOKIE_JAR_B:-}"' EXIT

RAND_EMAIL_A="probe_xff_$(date +%s)_$RANDOM@example.com"
curl -s -c "$COOKIE_JAR_A" -b "$COOKIE_JAR_A" "${APP_URL}/login" >/dev/null

REACHED_429_AT=0
for i in {1..10}; do
    CSRF_VAL=$(grep "XSRF-TOKEN" "$COOKIE_JAR_A" | awk '{print $NF}' || true)
    CSRF_DECODED=$(printf '%b' "${CSRF_VAL//%/\\x}")
    SPOOFED_IP="198.51.100.$i"

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIE_JAR_A" -b "$COOKIE_JAR_A" \
        -H "X-XSRF-TOKEN: $CSRF_DECODED" \
        -H "X-Forwarded-For: $SPOOFED_IP" \
        -H "Accept: application/json" \
        -d "email=${RAND_EMAIL_A}&password=wrong_password_$i" \
        "${APP_URL}/login")

    echo "  Percobaan $i [XFF: $SPOOFED_IP] -> HTTP $HTTP_CODE"

    if [ "$HTTP_CODE" = "429" ] && [ "$REACHED_429_AT" -eq 0 ]; then
        REACHED_429_AT=$i
    fi
done

if [ "$REACHED_429_AT" -gt 0 ] && [ "$REACHED_429_AT" -le 6 ]; then
    echo -e "${GREEN}[PASS] Skenario A berhasil: pembatasan laju HTTP 429 aktif pada percobaan ke-${REACHED_429_AT}.${NC}"
else
    echo -e "${RED}[FAIL] Skenario A GAGAL: tidak mencapai HTTP 429 pada percobaan ke-6 atau lebih awal (tercapai di: $REACHED_429_AT).${NC}"
    FAILED=1
fi

# ------------------------------------------------------------------------------
# Skenario B: Kontrol tanpa header X-Forwarded-For
# Target: WAJIB mencapai HTTP 429 pada percobaan ke-6.
# ------------------------------------------------------------------------------
echo ""
echo "--- Skenario B: Kontrol Normal Tanpa Header X-Forwarded-For ---"

# Reset zona rate limit Nginx agar pengujian kontrol memiliki baseline bersih
docker compose restart web >/dev/null 2>&1 || true
sleep 1

COOKIE_JAR_B=$(mktemp)
RAND_EMAIL_B="probe_ctrl_$(date +%s)_$RANDOM@example.com"
curl -s -c "$COOKIE_JAR_B" -b "$COOKIE_JAR_B" "${APP_URL}/login" >/dev/null

REACHED_429_CTRL_AT=0
for i in {1..7}; do
    CSRF_VAL=$(grep "XSRF-TOKEN" "$COOKIE_JAR_B" | awk '{print $NF}' || true)
    CSRF_DECODED=$(printf '%b' "${CSRF_VAL//%/\\x}")

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIE_JAR_B" -b "$COOKIE_JAR_B" \
        -H "X-XSRF-TOKEN: $CSRF_DECODED" \
        -H "Accept: application/json" \
        -d "email=${RAND_EMAIL_B}&password=wrong_password_$i" \
        "${APP_URL}/login")

    echo "  Percobaan $i [kontrol] -> HTTP $HTTP_CODE"

    if [ "$HTTP_CODE" = "429" ] && [ "$REACHED_429_CTRL_AT" -eq 0 ]; then
        REACHED_429_CTRL_AT=$i
    fi
done

if [ "$REACHED_429_CTRL_AT" -eq 6 ]; then
    echo -e "${GREEN}[PASS] Skenario B berhasil: kontrol normal mencapai HTTP 429 tepat pada percobaan ke-6.${NC}"
else
    echo -e "${RED}[FAIL] Skenario B GAGAL: kontrol normal tidak mencapai HTTP 429 pada percobaan ke-6 (tercapai di: $REACHED_429_CTRL_AT).${NC}"
    FAILED=1
fi

echo ""
echo -e "${BLUE}=================================================================${NC}"
if [ "$FAILED" -eq 0 ]; then
    echo -e "${GREEN}HASIL PROBE: SELURUH SKENARIO LULUS (PASS)${NC}"
    exit 0
else
    echo -e "${RED}HASIL PROBE: GAGAL (FAIL)${NC}"
    exit 1
fi

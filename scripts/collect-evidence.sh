#!/usr/bin/env bash
# Pengumpul bukti independen untuk pengawas. Jalankan oleh PEMILIK, bukan oleh agen.
# Pemakaian: bash scripts/collect-evidence.sh <tag-fase-sebelumnya> <label>   (mis. fase-2 fase3)
# Tambahkan --no-gates sebagai argumen ke-3 untuk melewati make check/test/verify dan build.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
PREV="${1:?tag fase sebelumnya, mis. fase-2}"
LABEL="${2:?label, mis. fase3}"
GATES=1; [ "${3:-}" = "--no-gates" ] && GATES=0
OUT=review; mkdir -p "$OUT"
mask() { sed -E "s/IDENTIFIED BY PASSWORD '[^']*'/IDENTIFIED BY PASSWORD '***'/g"; }
dc() { docker compose run --rm -T "$@"; }

{ echo "### git log $PREV..HEAD"; git log --oneline "$PREV"..HEAD
  echo "### git status"; git status -sb
  echo "### tags"; git tag -l
  echo "### diff stat"; git diff --stat "$PREV"..HEAD
  echo "### berkas sensitif di index"; git ls-files | grep -Ei '\.env|\.pem|\.key|secrets|sqlite' ; } > "$OUT/$LABEL-git.txt" 2>&1

{ echo "### php/node"; dc php php -v | head -2; docker compose --profile tools run --rm -T node node -v 2>/dev/null
  echo "### composer show"; for p in laravel/framework inertiajs/inertia-laravel pestphp/pest phpunit/phpunit pestphp/pest-plugin-laravel larastan/larastan laravel/pint; do dc php composer show "$p" | grep -E '^(name|versions)'; done
  echo "### composer outdated --direct"; dc php composer outdated --direct
  echo "### npm ls"; docker compose --profile tools run --rm -T node npm ls --depth=0
  echo "### npm outdated"; docker compose --profile tools run --rm -T node npm outdated; } > "$OUT/$LABEL-versions.txt" 2>&1

{ echo "### INFO SQL"
  docker compose exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -u root' <<'SQL'
SELECT VERSION();
SELECT user, host FROM mysql.user;
SHOW GRANTS FOR 'erp_app'@'%';
SHOW GRANTS FOR 'erp_migrator'@'%';
SELECT DELETE_RULE, COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='erp_trading' GROUP BY DELETE_RULE;
SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='erp_trading' ORDER BY 1,2;
SQL
  echo "### DUMP SKEMA (tanpa data)"
  docker compose exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb-dump -u root --no-data --skip-comments --triggers erp_trading'
} 2>&1 | mask > "$OUT/$LABEL-schema.txt"

if [ "$GATES" = 1 ]; then
  make check  2>&1 | tee "$OUT/$LABEL-check.txt"  >/dev/null
  make test   2>&1 | tee "$OUT/$LABEL-test.txt"   >/dev/null
  make verify 2>&1 | tee "$OUT/$LABEL-verify.txt" >/dev/null
  { docker compose --profile tools run --rm -T node npm run build
    docker compose --profile tools run --rm -T node npm run lint
    docker compose --profile tools run --rm -T node npm audit
    dc php composer audit; } > "$OUT/$LABEL-frontend-audit.txt" 2>&1
fi

# Isi semua berkas teks yang berubah sejak tag sebelumnya (tanpa lockfile, build, vendor).
{ git diff --name-only --diff-filter=ACMR "$PREV"..HEAD \
  | grep -Ev '(^|/)(vendor|node_modules|public/build)/|\.lock$|package-lock\.json$|\.(png|jpg|woff2?|ico)$' \
  | while read -r f; do
      [ -f "$f" ] || continue
      [ "$(wc -c < "$f")" -gt 200000 ] && { echo; echo "===== $f (dilewati: >200KB) ====="; continue; }
      echo; echo "===== $f ====="; cat "$f"
    done; } > "$OUT/$LABEL-code.txt" 2>&1

( cd "$OUT" && for f in "$LABEL-code.txt" "$LABEL-schema.txt"; do
    [ "$(wc -c < "$f")" -gt 90000 ] && split -b 90k -d "$f" "$f.part-" && echo "dipecah: $f"; done )
echo; echo "Selesai. Lampirkan berkas di folder review/ ke chat pengawas:"; ls -lh "$OUT"

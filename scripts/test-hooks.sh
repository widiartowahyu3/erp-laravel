#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

pass() {
    echo -e "${GREEN}[PASS]${NC} $1"
}

fail() {
    echo -e "${RED}[FAIL]${NC} $1"
    exit 1
}

echo "==> Running functional verification for Git hooks..."

# 1. Syntax check for all hooks
bash -n .githooks/pre-commit || fail "Syntax error in .githooks/pre-commit"
bash -n .githooks/commit-msg || fail "Syntax error in .githooks/commit-msg"
pass "Syntax check (bash -n) passed for all hooks"

# Ensure we cleanup any staged test files
cleanup_test_hooks() {
    git reset -q HEAD 2>/dev/null || true
    rm -f .env.forbidden_test \
          secrets/forbidden_secret.txt \
          tmp_leak_test.txt \
          id_ed25519_dummy_test \
          tmp_commit_msg.txt 2>/dev/null || true
}
trap cleanup_test_hooks EXIT INT TERM

# 2. Test pre-commit rejects .env file
touch .env.forbidden_test
git add -f .env.forbidden_test
if .githooks/pre-commit >/dev/null 2>&1; then
    fail "pre-commit GAGAL menolak file .env terlarang"
else
    pass "pre-commit sukses menolak file .env"
fi
git reset -q HEAD .env.forbidden_test
rm -f .env.forbidden_test

# 3. Test pre-commit rejects file in secrets/
touch secrets/forbidden_secret.txt
git add -f secrets/forbidden_secret.txt
if .githooks/pre-commit >/dev/null 2>&1; then
    fail "pre-commit GAGAL menolak file dalam direktori secrets/"
else
    pass "pre-commit sukses menolak file dalam secrets/"
fi
git reset -q HEAD secrets/forbidden_secret.txt
rm -f secrets/forbidden_secret.txt

# 4. Test pre-commit rejects live DB_APP_PASSWORD
# Construct string dynamically so this test script does not trip the pre-commit hook itself
TEST_SECRET_KEY="DB_APP_PASSWORD"
printf "%s=live_unmasked_secret_password_12345\n" "$TEST_SECRET_KEY" > tmp_leak_test.txt
git add tmp_leak_test.txt
if .githooks/pre-commit >/dev/null 2>&1; then
    fail "pre-commit GAGAL menolak live credential DB_APP_PASSWORD"
else
    pass "pre-commit sukses menolak live credential DB_APP_PASSWORD"
fi
git reset -q HEAD tmp_leak_test.txt
rm -f tmp_leak_test.txt

# 5. Test pre-commit rejects dummy private key
touch id_ed25519_dummy_test
git add -f id_ed25519_dummy_test
if .githooks/pre-commit >/dev/null 2>&1; then
    fail "pre-commit GAGAL menolak file cryptographic key"
else
    pass "pre-commit sukses menolak file cryptographic key"
fi
git reset -q HEAD id_ed25519_dummy_test
rm -f id_ed25519_dummy_test

# 6. Test commit-msg rejects non-conventional commit messages
echo "bad commit message without conventional format" > tmp_commit_msg.txt
if .githooks/commit-msg tmp_commit_msg.txt >/dev/null 2>&1; then
    fail "commit-msg GAGAL menolak format commit non-konvensional"
else
    pass "commit-msg sukses menolak pesan non-konvensional"
fi

# 7. Test commit-msg accepts valid conventional commit message
echo "chore(test): verify conventional commit format" > tmp_commit_msg.txt
if .githooks/commit-msg tmp_commit_msg.txt >/dev/null 2>&1; then
    pass "commit-msg menerima format Conventional Commits yang valid"
else
    fail "commit-msg menolak pesan Conventional Commits yang valid"
fi
rm -f tmp_commit_msg.txt

echo "==> All Git hook functional tests passed successfully."

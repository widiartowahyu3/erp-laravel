#!/usr/bin/env bash
set -euo pipefail

# Dependabot only tracks some of these files, so a bump in one must be mirrored in the others.
cd "$(dirname "${BASH_SOURCE[0]}")/.."

pin_of() {
    grep -oE "$1:[^ \"]+@sha256:[0-9a-f]{64}" "$2" | head -n 1
}

assert_same() {
    local image="$1" first="$2" second="$3" a b
    a="$(pin_of "$image" "$first")"
    b="$(pin_of "$image" "$second")"
    if [ -z "$a" ] || [ "$a" != "$b" ]; then
        echo "[FAIL] Pin '$image' berbeda: $first ($a) vs $second ($b)" >&2
        exit 1
    fi
    echo "[PASS] Pin '$image' sama di $first dan $second"
}

assert_same 'mariadb' compose.yaml compose.prod.yaml
assert_same 'nginxinc/nginx-unprivileged' compose.yaml docker/php/Dockerfile
assert_same 'node' docker/node/Dockerfile docker/php/Dockerfile

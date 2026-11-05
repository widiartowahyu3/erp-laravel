#!/usr/bin/env bash
# Fails when a relative Markdown link in the README or docs points at a file that does not exist.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
broken=0
checked=0

for file in "$ROOT"/README.md "$ROOT"/docs/*.md; do
    dir="$(dirname "$file")"

    while IFS= read -r target; do
        path="${target%%#*}"

        case "$path" in
            '' | http://* | https://* | mailto:*) continue ;;
        esac

        checked=$((checked + 1))

        if [ ! -e "$dir/$path" ]; then
            echo "TAUTAN MATI: ${file#"$ROOT"/} -> $target"
            broken=$((broken + 1))
        fi
    done < <(grep -oE '\]\([^) ]+\)' "$file" | sed -E 's/^\]\(//; s/\)$//')
done

echo "Tautan relatif diperiksa: $checked, mati: $broken"

[ "$broken" -eq 0 ]

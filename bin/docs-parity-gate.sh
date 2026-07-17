#!/usr/bin/env bash
# EN/RU documentation parity gate (F42).
#
# Two checks against the VitePress-served tree (docs/**.md, excluding
# docs/.vitepress/**, docs/README.md and docs/05_AI/** — the latter is an
# internal AI-guideline doc, not part of the public site nav and has no
# RU counterpart by design):
#
#   1. Language leak — no Cyrillic characters in an EN-tree page. A page
#      that is entirely (or mostly) Russian prose living under the EN path
#      is a leak, not a translation.
#   2. Structural parity — every EN page has a matching docs/ru/<path> file
#      and vice versa. Catches missing translations and orphaned RU pages.
set -euo pipefail

cd "$(dirname "$0")/.."

fail=0

en_pages=$(find docs -name '*.md' \
    -not -path 'docs/.vitepress/*' \
    -not -path 'docs/ru/*' \
    -not -path 'docs/05_AI/*' \
    -not -name 'README.md' \
    | sed 's#^docs/##' | sort)

ru_pages=$(find docs/ru -name '*.md' | sed 's#^docs/ru/##' | sort)

echo "[docs-parity] Checking for RU-in-EN language leaks..."
leaked=""
while IFS= read -r rel; do
    [ -z "$rel" ] && continue
    f="docs/$rel"
    if grep -qP '[а-яА-ЯёЁ]' "$f"; then
        leaked="$leaked $f"
    fi
done <<< "$en_pages"

if [ -n "$leaked" ]; then
    echo "[docs-parity] FAIL — Cyrillic text found in EN-tree page(s):" >&2
    for f in $leaked; do
        echo "  - $f" >&2
    done
    fail=1
else
    echo "[docs-parity] OK — no Cyrillic leaks in EN tree."
fi

echo "[docs-parity] Checking EN <-> RU structural parity..."
missing_ru=$(comm -23 <(echo "$en_pages") <(echo "$ru_pages"))
missing_en=$(comm -13 <(echo "$en_pages") <(echo "$ru_pages"))

if [ -n "$missing_ru" ]; then
    echo "[docs-parity] FAIL — EN pages with no RU counterpart:" >&2
    echo "$missing_ru" | sed 's/^/  - docs\/ru\//' >&2
    fail=1
fi

if [ -n "$missing_en" ]; then
    echo "[docs-parity] FAIL — RU pages with no EN counterpart:" >&2
    echo "$missing_en" | sed 's/^/  - docs\//' >&2
    fail=1
fi

if [ "$fail" -eq 0 ]; then
    echo "[docs-parity] OK — EN and RU trees are structurally in parity."
fi

exit "$fail"

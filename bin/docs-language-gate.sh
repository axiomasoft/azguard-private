#!/usr/bin/env bash
# Documentation language gate.
#
# The public documentation is English-only until the main docs are settled (the Russian
# tree was removed on purpose). A page with Cyrillic text in docs/ or the root README is a
# leak: either untranslated prose or a stray copy of an internal note.
set -euo pipefail

cd "$(dirname "$0")/.."

# Tracked files only: ignored local notes (docs/05_AI) are not published.
leaked=$(git ls-files -z -- 'docs/*.md' README.md | xargs -0 -r grep -lP '[а-яА-ЯёЁ]' || true)

if [ -n "$leaked" ]; then
    echo "[docs-language] FAIL — Cyrillic text in English documentation:" >&2
    echo "$leaked" | sed 's/^/  - /' >&2
    exit 1
fi

echo "[docs-language] OK — documentation is English-only."

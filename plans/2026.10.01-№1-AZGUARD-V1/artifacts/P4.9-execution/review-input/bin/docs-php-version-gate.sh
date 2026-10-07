#!/usr/bin/env bash
# Docs PHP-version drift gate (F43).
#
# Ensures the docs never advertise a PHP version below the floor declared in the
# root composer.json "php" constraint. Drift-proof: bump the composer floor and
# the forbidden set follows automatically — no need to touch this script.
#
# A hit is a PHP version expression below the floor (same major, lower minor;
# or any lower major). Keep PHP adjacent to the version so filenames such as
# Resource.php do not turn an unrelated Filament version into a false positive.
set -euo pipefail

cd "$(dirname "$0")/.."

floor=$(grep -oP '"php"\s*:\s*"[^"]*?\K[0-9]+\.[0-9]+' composer.json | head -1)
if [ -z "$floor" ]; then
    echo "[docs-php] FAIL — could not read PHP floor from composer.json" >&2
    exit 1
fi

major=${floor%%.*}
minor=${floor##*.}

# Forbidden tokens: same major with a lower minor, plus any lower major (>=5).
patterns=()
for m in $(seq 0 $((minor - 1))); do
    patterns+=("${major}\\.${m}")
done
for maj in $(seq 5 $((major - 1))); do
    patterns+=("${maj}\\.[0-9]+")
done

# Nothing below the floor is expressible (floor is X.0 and lowest major) — pass.
if [ ${#patterns[@]} -eq 0 ]; then
    echo "[docs-php] OK — floor PHP ${floor} has no lower version to guard against."
    exit 0
fi

regex="(^|[^[:alnum:]_])php([[:space:]]+version)?[[:space:]]*([><=^~]+[[:space:]]*)?($(IFS='|'; echo "${patterns[*]}"))([^[:digit:]]|$)"

echo "[docs-php] Floor from composer.json: PHP ${floor}. Scanning docs for lower versions..."

hits=$(grep -rnEi --include='*.md' --exclude-dir='.vitepress' "${regex}" docs || true)

if [ -n "$hits" ]; then
    echo "[docs-php] FAIL — docs advertise a PHP version below the composer floor (PHP ${floor}):" >&2
    echo "$hits" | sed 's/^/  - /' >&2
    exit 1
fi

echo "[docs-php] OK — no docs reference a PHP version below the composer floor."

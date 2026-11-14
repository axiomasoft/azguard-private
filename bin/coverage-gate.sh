#!/usr/bin/env bash
# Coverage gate for `composer check`: fails without a coverage driver unless AZGUARD_ALLOW_NO_COVERAGE=1.
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=bin/coverage-driver.sh
source "$(dirname "$0")/coverage-driver.sh"

if ! azguard_coverage_php; then
    if [[ "${AZGUARD_ALLOW_NO_COVERAGE:-}" == "1" ]]; then
        echo "[coverage-gate] NOT VERIFIED — no pcov/Xdebug and AZGUARD_ALLOW_NO_COVERAGE=1; CI still enforces this gate." >&2
        exit 0
    fi
    echo "[coverage-gate] FAIL — no coverage driver (pcov or Xdebug) in this PHP runtime. Install one, or opt out explicitly with AZGUARD_ALLOW_NO_COVERAGE=1." >&2
    exit 1
fi

# Invoke PHP explicitly: vendor/bin/pest's shebang is `/usr/bin/env php`
# and would otherwise pick a PATH binary that has no driver.
# Coverage of the full suite needs more than 1G (measured OOM at 1G with pcov).
# Keep each file's capture in a fresh worker; reused workers under-report executed lines.
coverage_worker_php_args=(-d memory_limit=1G "${AZGUARD_COVERAGE_PHP_ARGS[@]}")
XDEBUG_MODE=coverage "$AZGUARD_COVERAGE_PHP" -d memory_limit=-1 "${AZGUARD_COVERAGE_PHP_ARGS[@]}" vendor/bin/pest --fail-on-skipped --exclude-group=engines --exclude-group=redis --exclude-group=replica --exclude-group=mysql-engines --parallel --processes=4 --max-batch-size=1 "--passthru-php=${coverage_worker_php_args[*]}" --coverage --min=85

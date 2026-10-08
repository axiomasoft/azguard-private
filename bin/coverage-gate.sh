#!/usr/bin/env bash
# Coverage gate for `composer check` (F50). Honest-skip twin of
# bin/mutation-gate.sh — see that file for the driver-detection rationale.
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=bin/coverage-driver.sh
source "$(dirname "$0")/coverage-driver.sh"

if ! azguard_coverage_php; then
    cat >&2 <<'EOF'
[coverage-gate] SKIPPED — no coverage driver (pcov/xdebug) available in this
PHP runtime. CI (tests.yml `coverage` job) enforces --min=85 with Xdebug;
this is an infra gap locally, not a code-quality signal. Install pcov or
xdebug to run this gate before pushing.
EOF
    exit 0
fi

# Invoke PHP explicitly: vendor/bin/pest's shebang is `/usr/bin/env php`
# and would otherwise pick a PATH binary that has no driver.
XDEBUG_MODE=coverage "$AZGUARD_COVERAGE_PHP" "${AZGUARD_COVERAGE_PHP_ARGS[@]}" vendor/bin/pest --exclude-group=engines,redis,replica --coverage --min=85

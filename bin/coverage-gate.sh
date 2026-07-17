#!/usr/bin/env bash
# Coverage gate for `composer check` (F50). Honest-skip twin of
# bin/mutation-gate.sh — see that file for the driver-detection rationale.
set -euo pipefail

cd "$(dirname "$0")/.."

if ! php -m | grep -qiE '^(pcov|xdebug)$'; then
    cat >&2 <<'EOF'
[coverage-gate] SKIPPED — no coverage driver (pcov/xdebug) available in this
PHP runtime. CI (tests.yml `coverage` job) enforces --min=50 with Xdebug;
this is an infra gap locally, not a code-quality signal. Install pcov or
xdebug to run this gate before pushing.
EOF
    exit 0
fi

XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=50

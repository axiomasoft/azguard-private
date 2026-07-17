#!/usr/bin/env bash
# Coverage/mutation gate for `composer check`.
#
# Runs per-package Infection (core/filament/context) against fresh
# coverage, honoring diff-scoped mode on PRs (F50, ARCHITECT_REVIEW.md §4.6).
#
# Honest-skip contract: mutation testing REQUIRES a coverage driver
# (Xdebug or PCOV). Local dev boxes frequently don't have one installed
# (perf cost). Rather than let `composer check` fail for a reason
# unrelated to code quality, this script skips with a loud warning when
# no driver is available — CI (tests.yml/mutation.yml) always has one,
# so the gate is never silently skipped where it matters.
set -euo pipefail

cd "$(dirname "$0")/.."

COVERAGE_DIR="build/coverage"
PACKAGES=(core filament context)

has_coverage_driver() {
    php -m | grep -qiE '^(pcov|xdebug)$'
}

if ! has_coverage_driver; then
    cat >&2 <<'EOF'
[mutation-gate] SKIPPED — no coverage driver (pcov/xdebug) available in this
PHP runtime. Coverage/mutation cannot execute here; this is an infra gap,
not a code-quality signal. CI (tests.yml `coverage` job, mutation.yml) runs
with Xdebug and enforces this gate for real. Install pcov or xdebug locally
to run this gate before pushing:
  pecl install pcov   # or: pecl install xdebug
EOF
    exit 0
fi

mkdir -p "$COVERAGE_DIR"

echo "[mutation-gate] generating coverage (XML) once, reused per package..."
XDEBUG_MODE=coverage vendor/bin/pest --coverage-xml="$COVERAGE_DIR/xml" --min=0

DIFF_ARGS=()
if [[ "${MUTATION_GATE_DIFF_SCOPED:-0}" == "1" ]]; then
    BASE="${MUTATION_GATE_DIFF_BASE:-origin/main}"
    echo "[mutation-gate] diff-scoped mode against ${BASE}"
    DIFF_ARGS=(--git-diff-lines --git-diff-base="$BASE")
fi

for pkg in "${PACKAGES[@]}"; do
    echo "[mutation-gate] === ${pkg} ==="
    XDEBUG_MODE=coverage vendor/bin/infection \
        --configuration="infection.${pkg}.json5" \
        --coverage="$COVERAGE_DIR/xml" \
        --threads=4 \
        --no-progress \
        "${DIFF_ARGS[@]}"
done

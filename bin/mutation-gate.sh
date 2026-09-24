#!/usr/bin/env bash
# Native Pest mutation gate.
#
# Pest 4 bundles pest-plugin-mutate, whose runner keeps the coverage test IDs
# consistent with Pest. Infection 0.34 cannot resolve those IDs (P4.5).
# Each package starts from fresh coverage; scores are enforced independently.
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=bin/coverage-driver.sh
source "$(dirname "$0")/coverage-driver.sh"

if ! azguard_coverage_php; then
    cat >&2 <<'EOF'
[mutation-gate] SKIPPED — no coverage driver (pcov/xdebug) is available in this
PHP runtime. CI runs this gate with Xdebug; install pcov or xdebug locally to
obtain an enforced native Pest mutation score.
EOF
    exit 0
fi

if (($# == 0)); then
    packages=(core filament context)
else
    packages=("$@")
fi

run_package() {
    local package="$1"
    local path
    local ignored
    local min_score

    case "$package" in
        core)
            path='packages/core/src'
            # Console entrypoints and Facades are declarative framework adapters;
            # their domain behavior is exercised through their services.
            ignored='Commands,Facades'
            min_score=99
            ;;
        filament)
            path='packages/filament/src'
            # These directories declare Filament framework wiring; package behavior
            # is covered through the underlying policy and registry classes.
            ignored='Commands,Resources,Pages'
            min_score=99
            ;;
        context)
            path='packages/context/src'
            # Console entrypoints are declarative adapters; mutate the context
            # domain layer rather than generated command plumbing.
            ignored='Commands'
            min_score=99
            ;;
        *)
            echo "[mutation-gate] unknown package: $package" >&2
            exit 2
            ;;
    esac

    echo "[mutation-gate] === $package (minimum ${min_score}%) ==="
    # ParaTest workers inherit the CLI default (often 128M), not the parent
    # -d flags. Pass the same coverage and memory settings through.
    local passthru_php="-d memory_limit=1G"
    if ((${#AZGUARD_COVERAGE_PHP_ARGS[@]})); then
        passthru_php="${AZGUARD_COVERAGE_PHP_ARGS[*]} ${passthru_php}"
    fi
    XDEBUG_MODE=coverage "$AZGUARD_COVERAGE_PHP" "${AZGUARD_COVERAGE_PHP_ARGS[@]}" -d memory_limit=1G vendor/bin/pest \
        --mutate \
        --parallel \
        --processes=4 \
        --passthru-php="$passthru_php" \
        --path="$path" \
        --ignore="$ignored" \
        --covered-only \
        --min="$min_score" \
        --no-cache
}

for package in "${packages[@]}"; do
    run_package "$package"
done

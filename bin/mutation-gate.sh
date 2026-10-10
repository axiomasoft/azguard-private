#!/usr/bin/env bash
# Native Pest mutation gate.
#
# Pest 4 bundles pest-plugin-mutate, whose runner keeps the coverage test IDs
# consistent with Pest. Infection 0.34 cannot resolve those IDs.
# Each package starts from fresh coverage; scores are enforced independently.
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=bin/coverage-driver.sh
source "$(dirname "$0")/coverage-driver.sh"

if ! azguard_coverage_php; then
    if [[ "${AZGUARD_ALLOW_NO_COVERAGE:-}" == "1" ]]; then
        echo "[mutation-gate] NOT VERIFIED — no pcov/Xdebug and AZGUARD_ALLOW_NO_COVERAGE=1; CI still enforces this gate." >&2
        exit 0
    fi
    echo "[mutation-gate] FAIL — no coverage driver (pcov or Xdebug) in this PHP runtime. Install one, or opt out explicitly with AZGUARD_ALLOW_NO_COVERAGE=1." >&2
    exit 1
fi

# Verify and apply the development-only upstream compatibility fix before Pest loads the runner.
"$AZGUARD_COVERAGE_PHP" bin/prepare-mutation-runner.php

# One generated configuration applies the stand exclusions to every worker. Passing --exclude-group on the CLI
# again replaces these exclusions and can accidentally admit the replica lane to a SQLite coverage run.
config=".phpunit.mutation.$$.xml"
trap 'rm -f "$config"' EXIT
sed 's#^\( *\)<source>#\1<groups>\n\1    <exclude>\n\1        <group>engines</group>\n\1        <group>redis</group>\n\1        <group>replica</group>\n\1    </exclude>\n\1</groups>\n\n\1<source>#' phpunit.xml >"$config"
grep -q '<group>redis</group>' "$config" || { echo "[mutation-gate] could not derive $config from phpunit.xml" >&2; exit 2; }

if (($# == 0)); then
    packages=(core filament)
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
    # The parent merges the coverage of all workers: 1G ran out in CI (Allowed memory size exhausted), so it is unlimited.
    PAO_DISABLE=1 XDEBUG_MODE=coverage "$AZGUARD_COVERAGE_PHP" "${AZGUARD_COVERAGE_PHP_ARGS[@]}" -d memory_limit=-1 vendor/bin/pest \
        --configuration="$config" \
        --fail-on-skipped \
        --display-skipped \
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

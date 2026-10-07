#!/usr/bin/env bash
# Shared coverage-driver detection for coverage-gate.sh and mutation-gate.sh.
# Source this file; do not execute it.

azguard_php_has_coverage_driver() {
    local bin="$1"
    "$bin" -m 2>/dev/null | grep -qiE '^(pcov|xdebug)$'
}

# Sets AZGUARD_COVERAGE_PHP and AZGUARD_COVERAGE_PHP_ARGS.
# Prefers PATH `php`, then `php8.4`, so a Herd binary without a driver can
# fall back to the system PHP 8.4 that has Xdebug/PCOV.
azguard_coverage_php() {
    local candidates=()
    local bin

    if command -v php >/dev/null; then
        candidates+=("$(command -v php)")
    fi
    if command -v php8.4 >/dev/null; then
        candidates+=("$(command -v php8.4)")
    fi

    AZGUARD_COVERAGE_PHP=
    AZGUARD_COVERAGE_PHP_ARGS=()

    for bin in "${candidates[@]}"; do
        if ! azguard_php_has_coverage_driver "$bin"; then
            continue
        fi

        AZGUARD_COVERAGE_PHP="$bin"
        # PHPUnit refuses to collect coverage when PCOV and Xdebug are both
        # loaded. CI uses Xdebug; disable PCOV when both are present.
        if "$bin" -m 2>/dev/null | grep -qi '^pcov$' \
            && "$bin" -m 2>/dev/null | grep -qi '^xdebug$'; then
            AZGUARD_COVERAGE_PHP_ARGS=(-d pcov.enabled=0)
        fi

        return 0
    done

    return 1
}

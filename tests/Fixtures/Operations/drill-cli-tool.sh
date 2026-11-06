#!/usr/bin/env bash
set -euo pipefail
# CLI contract tests only: delegate real PHP except for the suite entry point; never launch Docker.
case "$(basename "$0")" in
    php)
        for argument in "$@"; do
            if [[ "$argument" == vendor/bin/pest ]]; then
                echo 'CLI fixture stub: application/acceptance suites were not executed.'
                read -r suite_exit < "$(dirname "$0")/../suite-exit.txt"
                exit "$suite_exit"
            fi
        done
        exec "${RUNBOOK_CLI_REAL_PHP:?}" "$@" ;;
    docker)
        printf '%s\n' "$*" >> "${RUNBOOK_CLI_DOCKER_LOG:?}"
        if [[ "${1:-}" == run ]]; then exit 23; fi
        exit 0 ;;
    *) exit 99 ;;
esac

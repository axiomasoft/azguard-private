#!/usr/bin/env bash
# Fail closed until publication inventory and a runnable upgrade fixture exist.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
target='worktree'
version='2.0.0-rc.1'
report=''
supported=false
usage='usage: bash bin/upgrade-drill.sh [--to=worktree] [--target-version=<version>] [--all-supported] [--report=<file>]'

for argument in "$@"; do
    case "$argument" in
        --to=worktree) target='worktree' ;;
        --target-version=?*) version="${argument#--target-version=}" ;;
        --all-supported) supported=true ;;
        --report=?*) report="${argument#--report=}" ;;
        *) echo "$usage" >&2; exit 2 ;;
    esac
done

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "invalid target version: $version" >&2
    exit 2
fi

sha="$(git -C "$root" rev-parse --verify HEAD)" || exit 2
if [[ -n "$report" ]]; then
    mkdir -p "$(dirname "$report")"
    php -r '
        $report = [
            "status" => "unavailable",
            "target" => $argv[2],
            "target_version" => $argv[3],
            "head_sha" => $argv[4],
            "all_supported" => $argv[5] === "true",
            "php_version" => PHP_VERSION,
            "baselines" => [],
            "reason" => "Published baselines and an executable baseline-to-2.0 fixture are not established; no upgrade was qualified.",
        ];
        if (file_put_contents($argv[1], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
            exit(2);
        }
    ' "$report" "$target" "$version" "$sha" "$supported"
fi

echo 'UNAVAILABLE: no proven published baseline and executable baseline-to-2.0 fixture are recorded for this drill.' >&2
echo 'The 2026-10-30 inventory in RELEASING.md is historical; obtain current split and Packagist evidence before qualification.' >&2
echo 'A fresh install or reconstructed schema is not a published upgrade baseline. Upgrade qualification has not passed.' >&2
exit 1

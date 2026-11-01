#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

set +e
bash "$root/bin/upgrade-drill.sh" --to=worktree --target-version=2.1.0-test --all-supported --report="$tmp/result.json" >"$tmp/output" 2>&1
status=$?
set -e
[[ "$status" == 1 ]]
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if ($report["status"] !== "unavailable" || $report["target"] !== "worktree"
        || $report["target_version"] !== "2.1.0-test" || $report["all_supported"] !== true
        || $report["baselines"] !== [] || strlen($report["head_sha"]) !== 40) { exit(1); }
' "$tmp/result.json"
set +e
bash "$root/bin/upgrade-drill.sh" --to=unknown >"$tmp/output" 2>&1
status=$?
set -e
[[ "$status" == 2 ]]

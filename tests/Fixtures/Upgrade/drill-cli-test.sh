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
[[ "$(<"$tmp/output")" == *'EXPECTED GATE: exit 1 means qualification is unavailable, not that an upgrade passed.'* ]]
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

# Exercise the qualification target and a report in a directory that does not yet exist.
set +e
bash "$root/bin/upgrade-drill.sh" --to=worktree --target-version=2.0.0-rc.1 --all-supported --report="$tmp/upgrade/report.json" >"$tmp/rc-output" 2>&1
status=$?
set -e
[[ "$status" == 1 ]]
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if ($report["target_version"] !== "2.0.0-rc.1" || $report["status"] !== "unavailable"
        || $report["all_supported"] !== true || $report["baselines"] !== []) { exit(1); }
' "$tmp/upgrade/report.json"

# Test the internal dispatcher with a mock, which is not in the publication registry.
mkdir -p "$tmp/artifacts/mock" "$tmp/results"
printf core >"$tmp/artifacts/mock/core.zip"
printf filament >"$tmp/artifacts/mock/filament.zip"
php -r '
    $entry = ["id" => "mock", "evidence" => "https://example.invalid/inventory", "sha256" => [
        "core" => hash_file("sha256", $argv[2]."/mock/core.zip"),
        "filament" => hash_file("sha256", $argv[2]."/mock/filament.zip")]];
    file_put_contents($argv[1], "<?php return ".var_export([$entry], true).";");
' "$tmp/registry.php" "$tmp/artifacts"
php "$root/tests/Fixtures/Upgrade/run-baselines.php" "$tmp/registry.php" "$tmp/artifacts" "$tmp/results" 2.0.0-rc.1
[[ -f "$tmp/results/mock/fixture.log" ]]
# A tampered artifact is refused before any fixture runs.
printf tampered >"$tmp/artifacts/mock/core.zip"
mkdir "$tmp/bad-results"
if php "$root/tests/Fixtures/Upgrade/run-baselines.php" "$tmp/registry.php" "$tmp/artifacts" "$tmp/bad-results" 2.0.0-rc.1 >"$tmp/bad-output" 2>&1; then
    exit 1
fi
[[ ! -e "$tmp/bad-results/mock" ]]
# Even with a matching artifact hash, a missing required checkpoint fails closed.
printf missing-check >"$tmp/artifacts/mock/core.zip"
php -r '
    $entries = require $argv[1];
    $entries[0]["sha256"]["core"] = hash_file("sha256", $argv[2]);
    file_put_contents($argv[1], "<?php return ".var_export($entries, true).";");
' "$tmp/registry.php" "$tmp/artifacts/mock/core.zip"
mkdir "$tmp/missing-results"
if php "$root/tests/Fixtures/Upgrade/run-baselines.php" "$tmp/registry.php" "$tmp/artifacts" "$tmp/missing-results" 2.0.0-rc.1 >"$tmp/missing-output" 2>&1; then
    exit 1
fi

# Exercise the top-level report in an isolated clone with the test-only registry.
printf core >"$tmp/artifacts/mock/core.zip"
php -r '
    $entries = require $argv[1];
    $entries[0]["sha256"]["core"] = hash_file("sha256", $argv[2]);
    file_put_contents($argv[1], "<?php return ".var_export($entries, true).";");
' "$tmp/registry.php" "$tmp/artifacts/mock/core.zip"
git clone --quiet --no-checkout --shared "$root" "$tmp/repo"
mkdir -p "$tmp/repo/bin" "$tmp/repo/tests/Fixtures/Upgrade/baselines/mock"
cp "$root/bin/upgrade-drill.sh" "$tmp/repo/bin/upgrade-drill.sh"
cp "$root/tests/Fixtures/Upgrade/run-baselines.php" "$tmp/repo/tests/Fixtures/Upgrade/run-baselines.php"
cp "$root/tests/Fixtures/Upgrade/baselines/mock/drill.sh" "$tmp/repo/tests/Fixtures/Upgrade/baselines/mock/drill.sh"
cp "$tmp/registry.php" "$tmp/repo/tests/Fixtures/Upgrade/baselines.php"
AZGUARD_UPGRADE_ARTIFACT_DIR="$tmp/artifacts" bash "$tmp/repo/bin/upgrade-drill.sh" --all-supported --report="$tmp/qualified.json" >"$tmp/qualified-output" 2>&1
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if ($report["status"] !== "passed" || count($report["baselines"]) !== 1
        || $report["baselines"][0]["id"] !== "mock" || !is_file($report["baselines"][0]["fixture_log"])) { exit(1); }
' "$tmp/qualified.json"

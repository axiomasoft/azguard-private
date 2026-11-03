#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
for argument in '--host=production' '--redis-url=redis://example.invalid' '--report=/tmp/output' '--report=build/../outside.json' '--report=' '--fixture=production' 'unknown'; do
    result=0
    bash "$root/bin/production-runbook-drill.sh" "$argument" >/dev/null 2>&1 || result=$?
    [[ "$result" == 2 ]] || { echo "Expected exit 2 for $argument, got $result" >&2; exit 1; }
done
result=0
bash "$root/bin/production-runbook-drill.sh" redis --self-test >/dev/null 2>&1 || result=$?
[[ "$result" == 2 ]] || exit 1
result=0
bash "$root/bin/production-runbook-drill.sh" --fixture=disposable --fixture=disposable >/dev/null 2>&1 || result=$?
[[ "$result" == 2 ]] || exit 1

mkdir -p "$root/build/production-runbook-drill"
work="$(mktemp -d "$root/build/production-runbook-drill/cli.XXXXXXXX")"
real_php="$(command -v php)"
mkdir "$work/bin"
ln -s "$root/tests/Fixtures/Operations/drill-cli-tool.sh" "$work/bin/php"
ln -s "$root/tests/Fixtures/Operations/drill-cli-tool.sh" "$work/bin/docker"
ln -s "$root/docs" "$work/escape"
result=0
bash "$root/bin/production-runbook-drill.sh" "--report=${work#"$root/"}/escape/report.json" >/dev/null 2>&1 || result=$?
[[ "$result" == 2 ]] || exit 1
output="${work#"$root/"}/result.json"

run_fixture() {
    printf '%s\n' "$1" > "$work/suite-exit.txt"
    PATH="$work/bin:$PATH" RUNBOOK_CLI_REAL_PHP="$real_php" RUNBOOK_CLI_DOCKER_LOG="$work/docker.log" \
        bash "$root/bin/production-runbook-drill.sh" "${@:2}" > "$work/run.log" 2>&1
}
verify_report() {
    "$real_php" -r '
        $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $fields = ["schema_version", "fixture", "mode", "status", "exit", "source_sha", "evidence_dir", "logs"];
        if (array_keys($report) !== $fields || $report["schema_version"] !== 1 || $report["fixture"] !== "disposable"
            || $report["mode"] !== $argv[2] || $report["exit"] !== (int) $argv[3]
            || $report["status"] !== ((int) $argv[3] === 0 ? "passed" : "failed")
            || !preg_match("/^[0-9a-f]{40}$/", $report["source_sha"])
            || !str_starts_with($report["evidence_dir"], "build/production-runbook-drill/run.")) exit(1);
        foreach ($report["logs"] as $path) if (!is_file($argv[4]."/".$path)) exit(1);
    ' "$root/$output" "$1" "$2" "$root"
}

# These run only CLI stubs, not the conductor-owned suites.
run_fixture 0 --fixture=disposable --self-test "--report=$output"
verify_report array 0
result=0
run_fixture 7 --self-test "--report=$output" || result=$?
[[ "$result" == 7 ]] || exit 1
verify_report array 7
result=0
run_fixture 0 --fixture=disposable "--report=$output" || result=$?
[[ "$result" == 23 ]] || exit 1
verify_report redis 23
[[ -s "$work/docker.log" ]] || exit 1
echo 'PASS: CLI parsing, safe report paths, report-v1 fields, success/failure exits and disposable Redis selection (stubs only).'

#!/usr/bin/env bash
# Execute the real workflow step using only local stubs: no network and no remote refs.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/bin"
php -r 'require $argv[1]."/vendor/autoload.php"; $w=Symfony\Component\Yaml\Yaml::parseFile($argv[1]."/.github/workflows/release-setup-probe.yml"); foreach ($w["jobs"]["probe"]["steps"] as $s) if (($s["name"] ?? "") === "Probe split push and dispatch permissions") { echo $s["run"]; exit; } exit(1);' "$root" > "$work/step.sh"
cat > "$work/bin/git" <<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case " $* " in
  *' rev-parse HEAD '*) printf '%040d\n' 1 ;;
  *' ls-remote '*)
    if [[ -e "$FIXTURE_PUSHED" ]]; then
      printf '%040d\t%s\n' 1 'refs/heads/release-setup-probe/123/2'
    fi ;;
  *' push '*)
    if [[ "${*: -1}" == :refs/* ]]; then
      [[ ${FIXTURE_CLEANUP_FAIL:-0} != 1 ]] || exit 53
    else
      : > "$FIXTURE_PUSHED"
      [[ ${FIXTURE_MAIN_FAIL:-0} != 1 ]] || exit 37
    fi ;;
esac
SH
cat > "$work/bin/gh" <<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$*" == 'workflow run attestation-setup.yml --repo axiomasoft/azguard --ref main' ]]
SH
chmod +x "$work/bin/git" "$work/bin/gh"
export PATH="$work/bin:$PATH" GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=2 GH_TOKEN=fixture
run_case() {
    local name="$1" expected="$2" main_fail="$3" cleanup_fail="$4" status=0
    export FIXTURE_LOG="$work/$name.log" FIXTURE_PUSHED="$work/$name.pushed"
    export FIXTURE_MAIN_FAIL="$main_fail" FIXTURE_CLEANUP_FAIL="$cleanup_fail"
    bash "$work/step.sh" > "$work/$name.out" 2>&1 || status=$?
    [[ "$status" == "$expected" ]] || { printf '%s: expected %s, got %s\n' "$name" "$expected" "$status" >&2; cat "$work/$name.out" >&2; exit 1; }
    grep -q -- '--force-with-lease=refs/heads/release-setup-probe/123/2:' "$FIXTURE_LOG" || { echo "$name: owned cleanup was not attempted" >&2; exit 1; }
    if [[ "$cleanup_fail" == 1 ]]; then
        grep -q 'manual recovery is required' "$work/$name.out" || { echo "$name: cleanup failure was hidden" >&2; exit 1; }
    fi
}
run_case success 0 0 0
run_case source_failure 37 1 0
run_case cleanup_failure 53 0 1
run_case original_and_cleanup_failure 37 1 1
# An original failure keeps its exact status even when cleanup also fails.
# The previous case expects that original status, never the cleanup status.
printf 'release setup probe cleanup fixtures passed\n'

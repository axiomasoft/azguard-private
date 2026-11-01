#!/usr/bin/env bash
# Negative proof of the secret gate: a synthetic key outside the repository must be detected (gitleaks exit 1).
# The key is generated at runtime and never written inside the repository.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

suffix="$(python3 -c 'import random, string; print("".join(random.SystemRandom().choices(string.ascii_uppercase + "234567", k=16)))')"
secret="$(python3 -c 'import random, string; print("".join(random.SystemRandom().choices(string.ascii_letters + string.digits, k=40)))')"
printf 'aws_access_key_id = AKIA%s\naws_secret_access_key = %s\n' "$suffix" "$secret" > "$tmp/credentials"

status=0
(cd "$root" && bash bin/security-tools.sh gitleaks -- dir --no-banner --redact "$tmp" > /dev/null 2>&1) || status=$?

if [ "$status" -ne 1 ]; then
    echo "test-security-gates: expected gitleaks exit 1 for a synthetic key, got $status" >&2
    exit 1
fi

echo "test-security-gates: synthetic key detected (exit 1)"

# The short-lived Bedrock rule must detect its token pattern, but not the
# equivalent regular expression that defines the rule in our configuration.
mkdir "$tmp/bedrock-positive" "$tmp/bedrock-negative"
python3 - "$root/.gitleaks.toml" "$tmp" <<'PY'
from pathlib import Path
import sys

config, tmp = Path(sys.argv[1]), Path(sys.argv[2])
lines = config.read_text().splitlines()
index = lines.index('id = "aws-amazon-bedrock-api-key-short-lived"')
regex = next(line for line in lines[index:] if line.startswith('regex = '))
(tmp / 'bedrock-negative' / 'rule.txt').write_text(regex + '\n')
(tmp / 'bedrock-positive' / 'token.txt').write_text(
    'bedrock-api-key-' + 'YmVkcm9jay5hbWF6b25hd3MuY29t' + '\n'
)
PY
status=0
(cd "$root" && bash bin/security-tools.sh gitleaks -- dir --no-banner --redact --ignore-gitleaks-allow --gitleaks-ignore-path="$tmp" --config="$root/.gitleaks.toml" --report-format=json --report-path="$tmp/bedrock-positive.json" "$tmp/bedrock-positive" >/dev/null 2>&1) || status=$?
if [ "$status" -ne 1 ]; then
    echo "test-security-gates: expected Bedrock token finding, got $status" >&2
    exit 1
fi
python3 - "$tmp/bedrock-positive.json" <<'PY'
import json, sys
with open(sys.argv[1]) as report:
    findings = json.load(report)
if not any(item.get('RuleID') == 'aws-amazon-bedrock-api-key-short-lived' for item in findings):
    raise SystemExit('test-security-gates: Bedrock rule omitted the token')
PY
status=0
(cd "$root" && bash bin/security-tools.sh gitleaks -- dir --no-banner --redact --ignore-gitleaks-allow --gitleaks-ignore-path="$tmp" --config="$root/.gitleaks.toml" "$tmp/bedrock-negative" >/dev/null 2>&1) || status=$?
if [ "$status" -ne 0 ]; then
    echo "test-security-gates: Bedrock rule source should not be a finding, got $status" >&2
    exit 1
fi
echo "test-security-gates: Bedrock token detected and rule source clean"

# Exercise the actual release config, including upstream-excluded paths and a
# secret introduced only in a merge commit, without retaining the test key.
repo="$tmp/repository"
mkdir "$repo"
git -C "$repo" init -q
git -C "$repo" config user.name 'Security fixture'
git -C "$repo" config user.email 'fixture@example.invalid'
printf 'base\n' > "$repo/base.txt"
git -C "$repo" add base.txt
git -C "$repo" commit -qm base
git -C "$repo" checkout -qb branch
printf 'branch\n' > "$repo/branch.txt"
git -C "$repo" add branch.txt
git -C "$repo" commit -qm branch
git -C "$repo" checkout -q -
printf 'main\n' > "$repo/main.txt"
git -C "$repo" add main.txt
git -C "$repo" commit -qm main
git -C "$repo" merge --no-commit --no-ff branch >/dev/null 2>&1
printf 'aws_access_key_id = AKIA%s # gitleaks:allow\naws_secret_access_key = %s\n' "$suffix" "$secret" > "$repo/package-lock.json"
git -C "$repo" add package-lock.json
git -C "$repo" commit -qm merge
git -C "$repo" rm -q package-lock.json
git -C "$repo" commit -qm remove
candidate="$(git -C "$repo" rev-parse HEAD)"

status=0
(cd "$root" && bash bin/security-tools.sh gitleaks -- git --no-banner --redact --ignore-gitleaks-allow --gitleaks-ignore-path="$tmp" --timeout 60 --config="$root/.gitleaks.toml" --log-opts="--full-history --diff-merges=first-parent $candidate" --report-format=json --report-path="$tmp/history.json" "$repo" >/dev/null 2>&1) || status=$?
if [ "$status" -ne 1 ]; then
    echo "test-security-gates: expected merge-history finding in upstream-excluded path, got $status" >&2
    exit 1
fi
python3 - "$tmp/history.json" <<'PY'
import json, sys
with open(sys.argv[1]) as report:
    findings = json.load(report)
if not any(item.get('File') == 'package-lock.json' for item in findings):
    raise SystemExit('test-security-gates: merge-history report omitted the fixture')
PY
echo "test-security-gates: merge-history fixture detected in upstream-excluded path"

# Prove the source-root ignore bypass in the pinned scanner itself, then verify
# the production runner rejects regular files and both kinds of symlink in each mode.
ignore_repo="$tmp/ignore-repository"
mkdir "$ignore_repo" "$tmp/empty-ignore"
git -C "$ignore_repo" init -q
git -C "$ignore_repo" config user.name 'Security fixture'
git -C "$ignore_repo" config user.email 'fixture@example.invalid'
printf 'aws_access_key_id = AKIA%s\n' "$suffix" > "$ignore_repo/credentials"
git -C "$ignore_repo" add credentials
git -C "$ignore_repo" commit -qm fixture
lock="${AZGUARD_TOOLS_LOCK:-$root/.github/security-tools.lock}"
version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["tools"]["gitleaks"]["version"])' "$lock")"
gitleaks="${AZGUARD_TOOLS_DIR:-$root/var/tools}/gitleaks-$version/gitleaks"
for mode in dir git; do
    args=("$mode" --no-banner --redact --ignore-gitleaks-allow --gitleaks-ignore-path="$tmp/empty-ignore" --config="$root/.gitleaks.toml" --report-format=json)
    status=0
    (cd "$root" && bash bin/security-tools.sh gitleaks -- "${args[@]}" --report-path="$tmp/$mode-original.json" "$ignore_repo" >/dev/null 2>&1) || status=$?
    [ "$status" -eq 1 ] || { echo "test-security-gates: $mode missed fixture without ignore (exit $status)" >&2; exit 1; }
    python3 - "$tmp/$mode-original.json" "$tmp/fingerprints" <<'PY'
import json, sys
with open(sys.argv[1]) as report:
    findings = json.load(report)
if not findings or not all(item.get('Fingerprint') for item in findings):
    raise SystemExit('test-security-gates: fixture report lacks fingerprints')
with open(sys.argv[2], 'w') as output:
    output.writelines(item['Fingerprint'] + '\n' for item in findings)
PY
    cp "$tmp/fingerprints" "$ignore_repo/.gitleaksignore"
    status=0
    (cd "$root" && "$gitleaks" "${args[@]}" --report-path="$tmp/$mode-bypassed.json" "$ignore_repo" >/dev/null 2>&1) || status=$?
    [ "$status" -eq 0 ] || { echo "test-security-gates: pinned $mode bypass was not reproduced (exit $status)" >&2; exit 1; }
    for ignore_kind in file symlink dangling; do
        case "$ignore_kind" in
            symlink) rm "$ignore_repo/.gitleaksignore"; ln -s "$tmp/fingerprints" "$ignore_repo/.gitleaksignore" ;;
            dangling) rm "$ignore_repo/.gitleaksignore"; ln -s "$tmp/missing-target" "$ignore_repo/.gitleaksignore" ;;
        esac
        status=0
        (cd "$root" && bash bin/security-tools.sh gitleaks -- "${args[@]}" --report-path="$tmp/$mode-guarded.json" "$ignore_repo" >/dev/null 2>&1) || status=$?
        [ "$status" -eq 2 ] || { echo "test-security-gates: $mode accepted $ignore_kind ignore (exit $status)" >&2; exit 1; }
    done
    rm "$ignore_repo/.gitleaksignore"
    status=0
    (cd "$root" && bash bin/security-tools.sh gitleaks -- "${args[@]}" --report-path="$tmp/$mode-after.json" "$ignore_repo" >/dev/null 2>&1) || status=$?
    [ "$status" -eq 1 ] || { echo "test-security-gates: $mode missed fixture after ignore removal (exit $status)" >&2; exit 1; }
done
echo "test-security-gates: dir/git source ignore bypass reproduced and blocked"

#!/usr/bin/env bash
# Isolated commit fixtures for the compatibility baseline guard.
set -euo pipefail

script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/compat-baseline-guard.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT

git -C "$fixture" init --quiet --initial-branch=main
git -C "$fixture" config user.name 'AzGuard fixture'
git -C "$fixture" config user.email 'fixture@example.invalid'

# expect <exit> <head> <labels-json> <case>
expect() {
    local expected="$1" head="$2" labels="$3" case="$4" actual
    set +e
    (cd "$fixture" && bash "$script" main "$head" "$labels" >/dev/null 2>&1)
    actual=$?
    set -e
    if [[ "$actual" != "$expected" ]]; then
        echo "guard fixture '${case}': exit ${actual}, expected ${expected}" >&2
        exit 1
    fi
    echo "ok: ${case} (exit ${actual})"
}

echo '{"schema": "urn:azguard:compatibility:1", "candidate": null, "artifacts": []}' > "$fixture/compatibility.json"
echo 'readme' > "$fixture/README.md"
git -C "$fixture" add compatibility.json README.md
git -C "$fixture" commit --quiet -m 'fixture: base'

git -C "$fixture" checkout --quiet -b other
echo 'more' >> "$fixture/README.md"
git -C "$fixture" commit --quiet -am 'fixture: another file'

git -C "$fixture" checkout --quiet -b pointer main
echo '{"schema": "urn:azguard:compatibility:1", "candidate": "0000000000000000000000000000000000000000", "artifacts": []}' > "$fixture/compatibility.json"
git -C "$fixture" commit --quiet -am 'fixture: new pointer'

# A pointer change merged into main after the branch point is not the pull request's change (three-dot diff).
git -C "$fixture" checkout --quiet main
echo '{"schema": "urn:azguard:compatibility:1", "candidate": "1111111111111111111111111111111111111111", "artifacts": []}' > "$fixture/compatibility.json"
git -C "$fixture" commit --quiet -am 'fixture: main moves the pointer'

expect 0 other '[]' 'no pointer change, no labels (main moved the pointer after the branch point)'
expect 0 other '["size/XS"]' 'no pointer change, other labels'
expect 0 pointer '["size/S", "compat-baseline"]' 'pointer change with the label'
expect 1 pointer '[]' 'pointer change without labels'
expect 1 pointer '["compat-baseline-later", "Compat-Baseline"]' 'pointer change with similar labels only'
expect 2 pointer 'not json' 'malformed labels'
expect 2 pointer '{"name": "compat-baseline"}' 'labels not a list'
expect 2 pointer '[["compat-baseline"]]' 'labels not names'
expect 2 missing-branch '[]' 'unknown head ref'

set +e
(cd "$fixture" && bash "$script" main pointer >/dev/null 2>&1)
missing=$?
set -e
if [[ "$missing" != 2 ]]; then
    echo "guard fixture 'missing argument': exit ${missing}, expected 2" >&2
    exit 1
fi
echo "ok: missing argument (exit 2)"

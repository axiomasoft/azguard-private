#!/usr/bin/env bash
# Conventional Commits gate: every non-merge commit in BASE..HEAD (or each header given with --header) must be
#   <type>(<scope>)!: <subject>
# with an allowed type, an optional kebab-case scope, and an English subject in printable ASCII of at most 100
# characters overall, without a trailing period. CI runs it on pull requests; run it before pushing.
set -euo pipefail

types='feat|fix|perf|refactor|docs|test|build|ci|style|chore|revert'
pattern="^(${types})(\\([a-z0-9]+(-[a-z0-9]+)*\\))?!?: [[:graph:]][[:print:]]*[^.[:space:]]\$"

check() {
    local header="$1" label="$2"
    if ! LC_ALL=C grep -Eq "$pattern" <<<"$header" || ((${#header} > 100)); then
        echo "error: ${label}: '${header}' is not '<type>(<scope>): <English subject>' (types: ${types//|/, }; ASCII; <= 100 chars; no trailing period)" >&2
        return 1
    fi
}

if [[ "${1:-}" == "--header" ]]; then
    shift
    status=0
    for header in "$@"; do
        check "$header" "header" || status=1
    done
    exit "$status"
fi

range="${1:?usage: check-commits.sh BASE [HEAD] | --header HEADER...}..${2:-HEAD}"
status=0
while IFS=$'\t' read -r sha header; do
    check "$header" "${sha:0:8}" || status=1
done < <(git log --no-merges --format='%H%x09%s' "$range")
exit "$status"

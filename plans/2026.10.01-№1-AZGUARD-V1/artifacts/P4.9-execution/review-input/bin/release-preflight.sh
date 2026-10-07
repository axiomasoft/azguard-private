#!/usr/bin/env bash
# Read-only release candidate check: VERSION must appear as a Keep a Changelog heading
# in CHANGELOG.md at GIT_REF (not the working tree).
set -euo pipefail

usage() {
    echo "usage: release-preflight.sh VERSION GIT_REF" >&2
    exit 2
}

version="${1:-}"
ref="${2:-}"

if [[ -z "${version}" || -z "${ref}" ]]; then
    usage
fi

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "error: invalid release version: ${version}" >&2
    exit 1
fi

if ! git rev-parse --verify -q "${ref}^{commit}" >/dev/null 2>&1; then
    echo "error: unknown git ref: ${ref}" >&2
    exit 1
fi

if ! changelog="$(git show "${ref}:CHANGELOG.md" 2>/dev/null)"; then
    echo "error: CHANGELOG.md is missing at ${ref}" >&2
    exit 1
fi

heading="## [${version}]"

# Match a complete Markdown heading, not a substring or a fenced example.
if ! awk -v heading="${heading}" '
    /^[[:space:]]*(```|~~~)/ { fenced = !fenced; next }
    !fenced {
        suffix = substr($0, length(heading) + 1)
        if (substr($0, 1, length(heading)) == heading &&
            (suffix == "" || suffix ~ /^ - [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/)) {
            found = 1
        }
    }
    END { exit !found }
' <<<"${changelog}"; then
    echo "error: ${ref}:CHANGELOG.md has no exact heading ${heading}" >&2
    exit 1
fi

echo "ok: ${ref} contains ${heading}"

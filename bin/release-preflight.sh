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

# Match a complete Markdown heading, not a substring or a fenced example.
has_heading() {
    awk -v heading="$1" '
        /^[[:space:]]*(```|~~~)/ { fenced = !fenced; next }
        !fenced {
            suffix = substr($0, length(heading) + 1)
            if (substr($0, 1, length(heading)) == heading &&
                (suffix == "" || suffix ~ /^ - [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/)) {
                found = 1
            }
        }
        END { exit !found }
    ' <<<"${changelog}"
}

# A pre-release X.Y.Z-<pre> is served by its own heading, else by the heading of the stable version X.Y.Z (one section
# serves the release candidates and the stable tag of the same commit).
heading="## [${version}]"
if ! has_heading "${heading}" && [[ "${version}" == *-* ]]; then
    heading="## [${version%%-*}]"
fi

if ! has_heading "${heading}"; then
    if [[ "${version}" == *-* ]]; then
        echo "error: ${ref}:CHANGELOG.md has no exact heading ## [${version}] or ## [${version%%-*}]" >&2
    else
        echo "error: ${ref}:CHANGELOG.md has no exact heading ${heading}" >&2
    fi
    exit 1
fi

echo "ok: ${ref} contains ${heading}"

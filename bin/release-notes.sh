#!/usr/bin/env bash
# Prints the body of the "## [VERSION]" section of CHANGELOG.md at GIT_REF: the GitHub release notes.
# The curated changelog is the only source of release notes; run release-preflight.sh first.
set -euo pipefail

version="${1:?usage: release-notes.sh VERSION GIT_REF}"
ref="${2:?usage: release-notes.sh VERSION GIT_REF}"

notes="$(git show "${ref}:CHANGELOG.md" | awk -v heading="## [${version}]" '
    /^[[:space:]]*(```|~~~)/ { fenced = !fenced }
    !fenced && /^## \[/ { if (inside) exit; if (index($0, heading) == 1) { inside = 1; next } }
    inside { print }
')"

if [[ -z "${notes//[[:space:]]/}" ]]; then
    echo "error: ${ref}:CHANGELOG.md has an empty or missing section ${version}" >&2
    exit 1
fi
printf '%s\n' "$notes" | sed -e '/./,$!d'

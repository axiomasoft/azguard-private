#!/usr/bin/env bash
# Verifies a release after the split: each split repository tag holds exactly the subtree of the monorepo tag, and
# Packagist serves that version from the split commit. Read-only.
#
#   bash bin/split-verify.sh <tag> [--repo-core=axiomasoft/azguard] [--repo-filament=axiomasoft/azguard-filament]
#                            [--remote-core=<url|path>] [--remote-filament=<url|path>] [--skip-packagist]
#
# Tree identity: `git rev-parse <tag>:packages/<dir>` must equal the tree of the split tag. When it does not, the
# differing entries are listed; entries of the split side that appear in `.github/split-allowlist.txt` (one path per line,
# `#` comments, empty by default) are ignored. The mapping `monorepo <sha> -> split <sha>` is printed per package.
# Packagist: `$SPLIT_PACKAGIST_BASE/<vendor>/<name>.json` (default https://repo.packagist.org/p2) must list the version
# with `source.reference` equal to the split commit and, for the Filament package, a `require` of the core on the same
# version. `SPLIT_ALLOWLIST` overrides the allowlist path and `SPLIT_MONOREPO` the repository whose tag is verified.
#
# Exit codes: 0 verified, 1 mismatch, 2 usage, 3 Packagist unreachable (never green).
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# The monorepo whose tag is verified; `SPLIT_MONOREPO` points the harness at a temporary one.
monorepo="${SPLIT_MONOREPO:-${root}}"

tag=""
repo_core="axiomasoft/azguard"
repo_filament="axiomasoft/azguard-filament"
remote_core=""
remote_filament=""
skip_packagist=0

usage() {
    echo "usage: split-verify.sh <tag> [--repo-core=<owner/name>] [--repo-filament=<owner/name>] [--remote-core=<url|path>] [--remote-filament=<url|path>] [--skip-packagist]" >&2
    exit 2
}

for arg in "$@"; do
    case "${arg}" in
        --repo-core=?*) repo_core="${arg#*=}" ;;
        --repo-filament=?*) repo_filament="${arg#*=}" ;;
        --remote-core=?*) remote_core="${arg#*=}" ;;
        --remote-filament=?*) remote_filament="${arg#*=}" ;;
        --skip-packagist) skip_packagist=1 ;;
        --*) usage ;;
        *) [[ -z "${tag}" ]] && tag="${arg}" || usage ;;
    esac
done

[[ "${tag}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || usage
version="${tag#v}"
remote_core="${remote_core:-https://github.com/${repo_core}.git}"
remote_filament="${remote_filament:-https://github.com/${repo_filament}.git}"
allowlist="${SPLIT_ALLOWLIST:-${root}/.github/split-allowlist.txt}"
packagist_base="${SPLIT_PACKAGIST_BASE:-https://repo.packagist.org/p2}"

work="$(mktemp -d)"
trap 'rm -r -f "${work}"' EXIT
status=0

log() {
    echo "[split-verify] $*"
}

fail() {
    echo "[split-verify] MISMATCH: $*" >&2
    status=1
}

mono_commit="$(git -C "${monorepo}" rev-parse --verify --quiet "refs/tags/${tag}^{commit}")" || { echo "error: no tag ${tag} in the monorepo" >&2; exit 2; }

# Allowlisted paths of the split side, without comments and empty lines.
allowed() {
    [[ -f "${allowlist}" ]] && grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "${allowlist}" || true
}

verify_package() {
    local dir="$1" remote="$2" name="$3"
    local split="${work}/split-${dir}.git"
    git init --bare --quiet "${split}"
    if ! git -C "${split}" fetch --quiet --no-tags "${remote}" "refs/tags/${tag}:refs/tags/${tag}" 2>"${work}/fetch.err"; then
        fail "${name}: cannot fetch tag ${tag} from ${remote}: $(head -n 1 "${work}/fetch.err")"
        return
    fi
    local split_commit mono_tree split_tree
    split_commit="$(git -C "${split}" rev-parse "refs/tags/${tag}^{commit}")"
    mono_tree="$(git -C "${monorepo}" rev-parse "${tag}:packages/${dir}")"
    split_tree="$(git -C "${split}" rev-parse "${split_commit}^{tree}")"
    log "${name}: monorepo ${mono_commit} -> split ${split_commit}"
    echo "${split_commit}" >"${work}/commit-${dir}"

    if [[ "${mono_tree}" == "${split_tree}" ]]; then
        log "${name}: tree identical (${mono_tree})"
        return
    fi

    # The trees differ: compare the entries, ignoring the allowlisted extra paths of the split side.
    git -C "${monorepo}" ls-tree -r "${tag}:packages/${dir}" | sort >"${work}/mono-${dir}.txt"
    git -C "${split}" ls-tree -r "${split_commit}" | sort >"${work}/split-${dir}.txt"
    while IFS= read -r path; do
        [[ -n "${path}" ]] && grep -v -F -e $'\t'"${path}" "${work}/split-${dir}.txt" >"${work}/filtered.txt" && mv "${work}/filtered.txt" "${work}/split-${dir}.txt" || true
    done < <(allowed)
    if diff -u "${work}/mono-${dir}.txt" "${work}/split-${dir}.txt" >"${work}/diff-${dir}.txt"; then
        log "${name}: tree identical apart from the allowlisted paths"
    else
        fail "${name}: the split tag ${tag} differs from packages/${dir} of the monorepo tag"
        sed 's/^/    /' "${work}/diff-${dir}.txt" >&2
    fi
}

# The reference of a version in a Packagist p2 document, minified entries expanded.
packagist_entry() {
    python3 - "$1" "$2" <<'PY'
import json, sys

path, version = sys.argv[1:3]
entries = next(iter(json.load(open(path))["packages"].values()))
current = {}
for entry in entries:
    for key, value in entry.items():
        if value == "__unset":
            current.pop(key, None)
        else:
            current[key] = value
    if current.get("version") == version or current.get("version_normalized", "").startswith(version + "."):
        print(json.dumps({"reference": current.get("source", {}).get("reference", ""), "require": current.get("require", {})}))
        break
else:
    sys.exit(3)
PY
}

verify_packagist() {
    local dir="$1" name="$2" vendor_name="$3"
    local document="${work}/packagist-${dir}.json"
    if ! curl -fsS --max-time 30 "${packagist_base}/${vendor_name}.json" -o "${document}" 2>"${work}/curl.err"; then
        echo "[split-verify] UNAVAILABLE: Packagist is not reachable for ${vendor_name}: $(head -n 1 "${work}/curl.err")" >&2
        exit 3
    fi
    local entry
    if ! entry="$(packagist_entry "${document}" "${version}")"; then
        fail "${name}: Packagist does not list ${version} of ${vendor_name}"
        return
    fi
    local reference expected
    reference="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["reference"])' "${entry}")"
    expected="$(cat "${work}/commit-${dir}" 2>/dev/null || true)"
    if [[ -z "${expected}" || "${reference}" != "${expected}" ]]; then
        fail "${name}: Packagist ${version} points to ${reference:-nothing}, the split tag is ${expected:-unknown}"
    else
        log "${name}: Packagist ${version} points to the split commit"
    fi
    if [[ "${dir}" == filament ]]; then
        local core_require
        core_require="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["require"].get("axiomasoft/azguard",""))' "${entry}")"
        if [[ "${core_require}" != "${version}" ]]; then
            fail "${name}: Packagist ${version} requires axiomasoft/azguard ${core_require:-nothing}, expected ${version}"
        else
            log "${name}: requires axiomasoft/azguard ${version}"
        fi
    fi
}

verify_package core "${remote_core}" "axiomasoft/azguard"
verify_package filament "${remote_filament}" "axiomasoft/azguard-filament"

if ((skip_packagist)); then
    log "Packagist check skipped"
else
    verify_packagist core "axiomasoft/azguard" "axiomasoft/azguard"
    verify_packagist filament "axiomasoft/azguard-filament" "axiomasoft/azguard-filament"
fi

if ((status == 0)); then
    log "verified ${tag}"
fi
exit "${status}"

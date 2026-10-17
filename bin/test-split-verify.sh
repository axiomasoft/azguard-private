#!/usr/bin/env bash
# Offline harness of bin/split-verify.sh: a temporary monorepo, two bare split repositories made with `git subtree split`,
# and recorded Packagist documents served through file:// URLs. No network, nothing outside the temporary directory.
set -Eeuo pipefail

script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/split-verify.sh"
work="$(mktemp -d)"
trap 'rm -r -f "${work}"' EXIT

git_config=(-c user.name='AzGuard fixture' -c user.email='fixture@example.invalid' -c commit.gpgsign=false -c tag.gpgsign=false)
mono="${work}/mono"
mkdir -p "${mono}/packages/core/src" "${mono}/packages/filament/src"
git -C "${mono}" init --quiet --initial-branch=main
echo '{"name":"axiomasoft/azguard"}' >"${mono}/packages/core/composer.json"
echo '<?php // core' >"${mono}/packages/core/src/A.php"
echo '{"name":"axiomasoft/azguard-filament"}' >"${mono}/packages/filament/composer.json"
echo '<?php // filament' >"${mono}/packages/filament/src/B.php"
git -C "${mono}" add -A
git -C "${mono}" "${git_config[@]}" commit --quiet -m 'fixture: monorepo'
git -C "${mono}" "${git_config[@]}" tag -a v1.0.0 -m 'AzGuard 1.0.0'

# A split repository: the subtree split pushed to a bare repository and tagged with the same name.
make_split() {
    local dir="$1" target="$2" sha
    sha="$(git -C "${mono}" "${git_config[@]}" subtree split --prefix="packages/${dir}" HEAD)"
    git init --bare --quiet "${target}"
    git -C "${mono}" push --quiet "${target}" "${sha}:refs/heads/main"
    git -C "${target}" "${git_config[@]}" tag -a v1.0.0 "${sha}" -m 'AzGuard 1.0.0'
    echo "${sha}"
}

core_sha="$(make_split core "${work}/core.git")"
filament_sha="$(make_split filament "${work}/filament.git")"

run() {
    SPLIT_MONOREPO="${mono}" SPLIT_ALLOWLIST="${work}/allowlist.txt" bash "${script}" v1.0.0 \
        --remote-core="${work}/core.git" --remote-filament="${work}/filament.git" "$@"
}

: >"${work}/allowlist.txt"

expect_status() {
    local expected="$1" label="$2"
    shift 2
    local status=0
    "$@" >"${work}/out.txt" 2>&1 || status=$?
    if [[ "${status}" -ne "${expected}" ]]; then
        echo "FAIL: ${label}: expected exit ${expected}, got ${status}" >&2
        cat "${work}/out.txt" >&2
        exit 1
    fi
}

# Packagist documents (minified p2 form: the second entry inherits the source of the first unless it overrides it).
packagist="${work}/packagist"
mkdir -p "${packagist}/axiomasoft"
write_packagist() {
    local core_ref="$1" filament_ref="$2" require="$3"
    cat >"${packagist}/axiomasoft/azguard.json" <<JSON
{"packages":{"axiomasoft/azguard":[{"name":"axiomasoft/azguard","version":"1.0.0","version_normalized":"1.0.0.0","source":{"type":"git","reference":"${core_ref}"},"require":{"php":"^8.3"}}]}}
JSON
    cat >"${packagist}/axiomasoft/azguard-filament.json" <<JSON
{"packages":{"axiomasoft/azguard-filament":[{"name":"axiomasoft/azguard-filament","version":"1.0.0","version_normalized":"1.0.0.0","source":{"type":"git","reference":"${filament_ref}"},"require":{"axiomasoft/azguard":"${require}"}}]}}
JSON
}

# 1. Identical trees pass.
expect_status 0 'identical trees' run --skip-packagist
grep -q "monorepo $(git -C "${mono}" rev-parse v1.0.0^{commit}) -> split ${core_sha}" "${work}/out.txt" || { echo 'FAIL: the mapping line is missing' >&2; exit 1; }

# 2. A stray file in a split tag fails; the allowlist admits it.
stray="${work}/stray"
git clone --quiet --branch main "${work}/core.git" "${stray}"
echo 'stray' >"${stray}/STRAY.md"
git -C "${stray}" add STRAY.md
git -C "${stray}" "${git_config[@]}" commit --quiet -m 'fixture: stray file'
git -C "${stray}" push --quiet origin HEAD:refs/heads/stray
stray_sha="$(git -C "${stray}" rev-parse HEAD)"
git -C "${work}/core.git" "${git_config[@]}" tag -f -a v1.0.0 "${stray_sha}" -m 'AzGuard 1.0.0' >/dev/null
expect_status 1 'a stray file in the split tag' run --skip-packagist
printf 'STRAY.md\n' >"${work}/allowlist.txt"
expect_status 0 'an allowlisted stray file' run --skip-packagist
: >"${work}/allowlist.txt"
git -C "${work}/core.git" "${git_config[@]}" tag -f -a v1.0.0 "${core_sha}" -m 'AzGuard 1.0.0' >/dev/null

# 3. A split tag with changed content fails.
changed="${work}/changed"
git clone --quiet --branch main "${work}/filament.git" "${changed}"
echo '<?php // altered' >"${changed}/src/B.php"
git -C "${changed}" "${git_config[@]}" commit --quiet -am 'fixture: altered file'
git -C "${changed}" push --quiet origin HEAD:refs/heads/altered
git -C "${work}/filament.git" "${git_config[@]}" tag -f -a v1.0.0 "$(git -C "${changed}" rev-parse HEAD)" -m 'AzGuard 1.0.0' >/dev/null
expect_status 1 'an altered file in the split tag' run --skip-packagist
git -C "${work}/filament.git" "${git_config[@]}" tag -f -a v1.0.0 "${filament_sha}" -m 'AzGuard 1.0.0' >/dev/null

# 4. A missing split tag fails.
git -C "${work}/core.git" tag -d v1.0.0 >/dev/null
expect_status 1 'a missing split tag' run --skip-packagist
git -C "${work}/core.git" "${git_config[@]}" tag -a v1.0.0 "${core_sha}" -m 'AzGuard 1.0.0'

# 5. Packagist: matching references pass; a wrong reference, a wrong core requirement and a missing version fail.
export SPLIT_PACKAGIST_BASE="file://${packagist}"
write_packagist "${core_sha}" "${filament_sha}" '1.0.0'
expect_status 0 'matching Packagist documents' run
write_packagist "$(printf 'f%.0s' {1..40})" "${filament_sha}" '1.0.0'
expect_status 1 'a Packagist reference that is not the split commit' run
write_packagist "${core_sha}" "${filament_sha}" '1.0.1'
expect_status 1 'a Filament package that requires another core version' run
rm -f "${packagist}/axiomasoft/azguard-filament.json"
expect_status 3 'an unreachable Packagist document' run
write_packagist "${core_sha}" "${filament_sha}" '1.0.0'
sed -i 's/"version":"1.0.0"/"version":"0.9.0"/; s/"version_normalized":"1.0.0.0"/"version_normalized":"0.9.0.0"/' "${packagist}/axiomasoft/azguard.json"
expect_status 1 'a version Packagist does not list' run

echo 'split-verify fixtures: PASS'

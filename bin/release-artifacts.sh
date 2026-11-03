#!/usr/bin/env bash
# Release artifacts: the package archives, a CycloneDX SBOM of each package's production dependencies and SHA256SUMS.
#
#   bash bin/release-artifacts.sh <version> <outdir>
#
# Writes the core archive, source and dependency SBOMs, lock, mapping and SHA256SUMS.
# Filament is not a release artifact. The release workflow runs this same script.
# The archive builder is the one of bin/consumer-fixture.sh (build_archives): a copy of the package with the version set
# in the copy only, then `composer archive`. The SBOM is made from a temporary project that requires the archive alone
# with --no-dev, so it holds the package and its production dependencies and no development tool.
#
# Exit codes: 0 success, 1 failure, 2 usage.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ $# -ne 2 || ! "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "usage: release-artifacts.sh <version> <outdir>" >&2
    exit 2
fi
version="$1"
out="$2"
mkdir -p "${out}"
out="$(cd "${out}" && pwd)"
if [[ -n "$(find "${out}" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    echo "error: output directory must be empty" >&2
    exit 1
fi

work="$(mktemp -d)"
trap 'rm -r -f "${work}"' EXIT

log() {
    echo "[release-artifacts] $*"
}

# Archives are built from COPIES: the version is set only in the copy, never in the source composer.json. The zip is
# kept under its release name in <outdir> and in the artifact repository of the SBOM projects.
build_archive() {
    local package="$1" copy="${work}/pkg-${package}" built="${work}/built-${package}"
    mkdir -p "${built}" "${work}/archives"
    if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
        cp -a "${root}/packages/${package}" "${copy}"
    else
        # A local rehearsal is bound to committed HEAD, not an uncommitted tree.
        mkdir -p "${copy}"
        git -C "${root}" archive --format=tar "HEAD:packages/${package}" | tar -xf - -C "${copy}"
    fi
    php -r '
        $file = $argv[1];
        $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $json["version"] = $argv[2];
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    ' "${copy}/composer.json" "${version}"
    composer archive --format=zip --dir="${built}" --working-dir="${copy}" --no-interaction >/dev/null
    local zip
    zip="$(find "${built}" -maxdepth 1 -type f -name '*.zip' | head -n 1)"
    [[ -n "${zip}" ]] || { echo "error: composer archive produced no zip for ${package}" >&2; exit 1; }
    cp "${zip}" "${out}/azguard-${package}-${version}.zip"
    python3 "${root}/packages/core/.github/canonicalize-release-zip.py" "${out}/azguard-${package}-${version}.zip"
    cp "${zip}" "${work}/archives/$(basename "${zip}")"
}

# The production SBOM of a package: a project that requires the archives with --no-dev, scanned by the pinned syft.
build_sbom() {
    local package="$1" name="axiomasoft/azguard"
    local project="${work}/sbom-${package}"
    mkdir -p "${project}"
    cat >"${project}/composer.json" <<JSON
{
    "name": "azguard/sbom-${package}",
    "require": {"${name}": "${version}"},
    "repositories": [{"type": "artifact", "url": "${work}/archives"}],
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON
    (cd "${project}" && composer update --no-dev --no-scripts --no-plugins --no-interaction --no-progress >/dev/null)
    sha256sum "${project}/composer.lock" > "${work}/sbom-${package}.lock.sha256"
    # Audit the exact production lock used for the SBOM, not the monorepo lock.
    (cd "${project}" && composer audit --locked --no-dev --abandoned=fail --no-interaction)
    # Query the same production resolution against OSV before using it for the SBOM.
    bash "${root}/bin/security-tools.sh" osv-scanner -- scan source --lockfile="${project}/composer.lock" --config="${root}/.github/osv-scanner.toml"
    sha256sum --check "${work}/sbom-${package}.lock.sha256"
    # The scan reads composer.lock only; vendor/ is not part of the evidence.
    bash "${root}/bin/security-tools.sh" syft -- "dir:${project}" -o "cyclonedx-json@1.6=${out}/sbom-${package}.cdx.json" --quiet
    sha256sum --check "${work}/sbom-${package}.lock.sha256"
    python3 - "${out}/sbom-${package}.cdx.json" "${name}" "${version}" <<'PY'
import json, sys

path, name, version = sys.argv[1:4]
bom = json.load(open(path))
# Keep the composer components only: the package itself is the subject, not a component of its own SBOM.
components = [c for c in bom.get("components", []) if str(c.get("purl", "")).startswith("pkg:composer/") and c.get("name") != name.split("/")[-1]]
components = [c for c in components if f"{c.get('group', '')}/{c.get('name')}".strip("/") != name]
bom["components"] = components
bom.setdefault("metadata", {})["component"] = {"type": "library", "name": name, "version": version, "purl": f"pkg:composer/{name}@{version}"}
dev_tools = ("pestphp/", "phpstan/", "larastan/", "laravel/pint", "rector/", "orchestra/testbench", "phpunit/")
bad = [f"{c.get('group', '')}/{c.get('name')}".strip("/") for c in components if f"{c.get('group', '')}/{c.get('name')}".strip("/").startswith(dev_tools)]
if bad:
    sys.exit(f"development tools in the production SBOM: {bad}")
if bom.get("bomFormat") != "CycloneDX" or not components:
    sys.exit("not a CycloneDX document with components")
json.dump(bom, open(path, "w"), indent=2)
PY
    cp "${project}/composer.lock" "${out}/resolution-${package}.lock"
    sha256sum --check "${work}/sbom-${package}.lock.sha256"
    cmp "${project}/composer.lock" "${out}/resolution-${package}.lock"
    php "${root}/bin/package-evidence.php" --check-resolved-sbom "${out}/resolution-${package}.lock" "${out}/sbom-${package}.cdx.json" "${name}" "${version}"
}

log "version ${version}, output ${out}"
for package in core; do
    build_archive "${package}"
done
for package in core; do
    build_sbom "${package}"
done

for package in core; do
    name=axiomasoft/azguard
    php "${root}/bin/package-evidence.php" --source-sbom "${out}/azguard-${package}-${version}.zip" "${name}" "${version}" "${out}/source-${package}.cdx.json"
    php "${root}/bin/package-evidence.php" --check-source-sbom "${out}/azguard-${package}-${version}.zip" "${name}" "${version}" "${out}/source-${package}.cdx.json"
    for bom in "${out}/source-${package}.cdx.json" "${out}/sbom-${package}.cdx.json"; do
        schema_version="$(php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $v=$j["specVersion"]??""; if (!in_array($v,["1.4","1.5","1.6"],true)) exit(1); echo "v".str_replace(".","_",$v);' "${bom}")"
        bash "${root}/bin/security-tools.sh" cyclonedx-cli -- validate --input-file "${bom}" --input-format json --input-version "${schema_version}" --fail-on-errors
    done
done

for package in core; do
    php "${root}/bin/package-evidence.php" --build-manifest "${package}" "${version}" "${out}" "${out}/mapping-${package}.json"
done
(cd "${out}" && sha256sum "azguard-core-${version}.zip" source-core.cdx.json sbom-core.cdx.json resolution-core.lock mapping-core.json >SHA256SUMS)
(cd "${out}" && sha256sum --strict --check SHA256SUMS)
log "artifacts:"
ls -1 "${out}" | sed 's/^/  /'

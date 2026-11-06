#!/usr/bin/env bash
# Only attest a ZIP rebuilt here after verifying its source, run, SBOMs and mapping.
set -Eeuo pipefail
[[ "${GITHUB_REF:-}" =~ ^refs/heads/release-candidate/(v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?)/([1-9][0-9]*)/([1-9][0-9]*)$ ]] || { echo 'invalid staging ref' >&2; exit 1; }
tag="${BASH_REMATCH[1]}" run_id="${BASH_REMATCH[3]}" attempt="${BASH_REMATCH[4]}" version="${BASH_REMATCH[1]#v}"
case "${GITHUB_REPOSITORY:-}" in
  axiomasoft/azguard) package=core ;;
  *) echo 'untrusted split repository' >&2; exit 1 ;;
esac
[[ -n "${GH_TOKEN:-}" && -n "${RUNNER_TEMP:-}" && -f dist/package.zip && ! -L dist/package.zip ]] || { echo 'producer prerequisites unavailable' >&2; exit 1; }
source_dir="$RUNNER_TEMP/verified-release-source"
[[ -f "$source_dir/bin/package-evidence.php" && -f "$source_dir/bin/security-tools.sh" && -f "$source_dir/.github/security-tools.lock" ]] || { echo 'authenticated source checkout missing' >&2; exit 1; }
bundle="$RUNNER_TEMP/release-bundle"
[[ ! -e "$bundle" ]] || { echo 'release bundle destination already exists' >&2; exit 1; }
gh run download "$run_id" --repo axiomasoft/azguard-private --name "release-artifacts-$attempt" --dir "$bundle"
[[ -f "$bundle/SHA256SUMS" && ! -L "$bundle/SHA256SUMS" ]] || exit 1
printf '%s\n' "azguard-core-$version.zip" source-core.cdx.json sbom-core.cdx.json resolution-core.lock mapping-core.json | LC_ALL=C sort > "$RUNNER_TEMP/expected-subjects"
awk '{print $2}' "$bundle/SHA256SUMS" | LC_ALL=C sort > "$RUNNER_TEMP/actual-subjects"
diff -u "$RUNNER_TEMP/expected-subjects" "$RUNNER_TEMP/actual-subjects"
while IFS= read -r file; do [[ -f "$bundle/$file" && ! -L "$bundle/$file" ]] || exit 1; done < "$RUNNER_TEMP/expected-subjects"
(cd "$bundle" && sha256sum --strict --check SHA256SUMS)
cmp dist/package.zip "$bundle/azguard-$package-$version.zip"
php "$source_dir/bin/package-evidence.php" --verify-producer-manifest "$source_dir" "$PWD" "$bundle" "$package" "$tag" "$run_id" "$attempt"
for bom in "$bundle/source-$package.cdx.json" "$bundle/sbom-$package.cdx.json"; do
    schema_version="$(php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $v=$j["specVersion"]??""; if (!in_array($v,["1.4","1.5","1.6"],true)) exit(1); echo "v".str_replace(".","_",$v);' "$bom")"
    bash "$source_dir/bin/security-tools.sh" cyclonedx-cli -- validate --input-file "$bom" --input-format json --input-version "$schema_version" --fail-on-errors
done
cp "$bundle/source-$package.cdx.json" dist/source.cdx.json
cp "$bundle/sbom-$package.cdx.json" dist/resolved.cdx.json
cp "$bundle/mapping-$package.json" dist/mapping.json
printf 'Verified own ZIP, mapping and both SBOMs before attestation.\n'

#!/usr/bin/env bash
set -Eeuo pipefail
[[ ( $# == 3 || ( $# == 4 && "$4" == --dispatch-final ) ) && "$1" =~ ^[0-9a-f]{40}$ && "$2" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || { echo 'invalid candidate or tag' >&2; exit 2; }
sha="$1" tag="$2" dist="$(realpath "$3")" version="${2#v}"
[[ ${GITHUB_RUN_ID:-} =~ ^[0-9]+$ && ${GITHUB_RUN_ATTEMPT:-} =~ ^[0-9]+$ ]] || { echo 'missing release run identity' >&2; exit 2; }
staging="release-candidate/$tag/$GITHUB_RUN_ID/$GITHUB_RUN_ATTEMPT"
[[ "$(git rev-parse HEAD)" == "$sha" ]] || { echo 'checkout does not match candidate SHA' >&2; exit 1; }
[[ "$(git rev-parse "refs/tags/$tag^{commit}")" == "$sha" ]] || { echo 'tag does not resolve to candidate SHA' >&2; exit 1; }
(cd "$dist" && sha256sum --check SHA256SUMS)
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
for package in core filament; do
    repo=azguard
    [[ "$package" == filament ]] && repo=azguard-filament
    git init -q "$tmp/$package"
    git -C "$tmp/$package" fetch --quiet --depth=1 "https://github.com/axiomasoft/$repo.git" "refs/heads/$staging:refs/heads/$staging"
    [[ "$(git rev-parse "$sha:packages/$package")" == "$(git -C "$tmp/$package" rev-parse "refs/heads/$staging^{tree}")" ]] || { echo "split tree mismatch: $package" >&2; exit 1; }
    split_sha="$(git -C "$tmp/$package" rev-parse "refs/heads/$staging^{commit}")"
    [[ "$package" != core ]] || core_split_sha="$split_sha"
    [[ "$package" != filament ]] || filament_split_sha="$split_sha"
    git -C "$tmp/$package" checkout --quiet --detach "$split_sha"
    cp -a "$tmp/$package" "$tmp/copy-$package"
    rm -rf "$tmp/copy-$package/.git"
    php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR); $j["version"]=$argv[2]; file_put_contents($p,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");' "$tmp/copy-$package/composer.json" "$version"
    mkdir "$tmp/archive-$package"
    composer archive --format=zip --dir="$tmp/archive-$package" --working-dir="$tmp/copy-$package" --no-interaction
    python3 - "$dist/azguard-$package-$version.zip" "$tmp/archive-$package" <<'PY'
import pathlib, sys, zipfile
files = list(pathlib.Path(sys.argv[2]).glob('*.zip'))
if len(files) != 1:
    raise SystemExit('expected exactly one rebuilt archive')
def contents(path):
    with zipfile.ZipFile(path) as archive:
        names = [name for name in archive.namelist() if not name.endswith('/')]
        if len(names) != len(set(names)):
            raise ValueError('duplicate archive member')
        return {name: archive.read(name) for name in names}
if contents(sys.argv[1]) != contents(files[0]):
    raise SystemExit('release archive does not match split tree')
PY
    # Verify the actual release ZIP, not the locally rebuilt comparison archive.
    # A public tag workflow may still be starting; failures remain failures after
    # the bounded wait, including missing CLI support or unavailable attestations.
    verified=false
    for attempt in {1..20}; do
        if gh attestation verify "$dist/azguard-$package-$version.zip" \
            --repo "axiomasoft/$repo" \
            --signer-workflow "axiomasoft/$repo/.github/workflows/provenance.yml" \
            --signer-digest "$split_sha" --source-digest "$split_sha" \
            --source-ref "refs/heads/$staging"; then
            verified=true
            break
        fi
        [[ "$attempt" == 20 ]] || sleep 30
    done
    [[ "$verified" == true ]] || { echo "public split attestation verification failed: $package" >&2; exit 1; }
done

if [[ ${4:-} == --dispatch-final ]]; then
    gh workflow run provenance.yml --repo axiomasoft/azguard --ref "$staging" \
        -f "candidate=$sha" -f "run_id=$GITHUB_RUN_ID" -f "run_attempt=$GITHUB_RUN_ATTEMPT"
    for artifact in sbom-core.cdx.json sbom-filament.cdx.json SHA256SUMS; do
        verified=false
        for attempt in {1..20}; do
            if gh attestation verify "$dist/$artifact" --repo axiomasoft/azguard \
                --signer-workflow axiomasoft/azguard/.github/workflows/provenance.yml \
                --signer-digest "$core_split_sha" --source-digest "$core_split_sha" \
                --source-ref "refs/heads/$staging" --predicate-type https://axiomasoft.github.io/azguard/attestations/release-artifact-relay/v1 --format json > "$tmp/verified.json" &&
                jq -e --arg sha "$sha" --arg run "$GITHUB_RUN_ID" --arg attempt "$GITHUB_RUN_ATTEMPT" \
                    'any(.[]; .verificationResult.statement.predicate | .candidate == $sha and .run_id == $run and .run_attempt == $attempt)' "$tmp/verified.json" > /dev/null; then
                verified=true
                break
            fi
            [[ "$attempt" == 20 ]] || sleep 30
        done
        [[ "$verified" == true ]] || { echo "public final artifact attestation verification failed: $artifact" >&2; exit 1; }
    done
    if [[ -n ${GITHUB_OUTPUT:-} ]]; then
        printf 'core_sha=%s\nfilament_sha=%s\n' "$core_split_sha" "$filament_split_sha" >> "$GITHUB_OUTPUT"
    fi
fi

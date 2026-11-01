#!/usr/bin/env bash
# Exercise the split verifier without a network or a published tag.
set -Eeuo pipefail
verifier="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/verify-release-split.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="$tmp/gitconfig"
git config --global user.name 'Split fixture'
git config --global user.email 'split-fixture@example.invalid'
git config --global "url.file://$tmp/remotes/.insteadOf" 'https://github.com/axiomasoft/'
git config --global --add "url.file://$tmp/remotes/.insteadOf" 'https://x-access-token@github.com/axiomasoft/'
git config --global --add "url.file://$tmp/remotes/.insteadOf" 'https://x-access-token:@github.com/axiomasoft/'
git config --global --add "url.file://$tmp/remotes/.insteadOf" 'https://x-access-token:fixture@github.com/axiomasoft/'
mkdir -p "$tmp/work/packages/core" "$tmp/work/packages/filament" "$tmp/remotes" "$tmp/dist"
mkdir "$tmp/bin"
export PATH="$tmp/bin:$PATH" SPLIT_FIXTURE="$tmp"
export GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=2
cat > "$tmp/bin/gh" <<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ ${RELAY_FIXTURE:-} == 1 ]]; then
    if [[ "$1" == api && "$2" == "repos/axiomasoft/azguard-private/actions/runs/123/attempts/2" ]]; then
        jq -n --arg sha "${RELAY_SHA:-$FIXTURE_CANDIDATE}" --arg tag "${RELAY_TAG:-v2.0.0-rc.1}" --arg path "${RELAY_PATH:-.github/workflows/release.yml@refs/tags/v2.0.0-rc.1}" --argjson attempt "${RELAY_ATTEMPT:-2}" --arg status "${RELAY_RUN_STATUS:-in_progress}" '{id:123,head_sha:$sha,head_branch:$tag,event:"push",path:$path,run_attempt:$attempt,status:$status,conclusion:null,repository:{full_name:"axiomasoft/azguard-private"}}'
    elif [[ "$*" == 'api --paginate --slurp repos/axiomasoft/azguard-private/actions/runs/123/attempts/2/jobs?per_page=100' ]]; then
        jq -n --arg sha "$FIXTURE_CANDIDATE" --arg mode "${RELAY_JOB_MODE:-}" --arg target "${RELAY_JOB_TARGET:-Release evidence gate}" '
          [["Validate before release", "Require an annotated tag"],
           ["Consumer archives · L12", "Core archive in a clean Laravel app"],
           ["Consumer archives · L13", "Core archive in a clean Laravel app"],
           ["Release artifacts", "Build the archives, the SBOMs and SHA256SUMS"],
           ["Release evidence gate", "Evaluate release evidence"],
           ["Split validated packages / Split azguard", "Split packages/core -> azguard"],
           ["Split validated packages / Split azguard-filament", "Split packages/filament -> azguard-filament"]] |
          to_entries | map({id:(.key + 1),run_id:123,head_sha:$sha,name:.value[0],status:"completed",conclusion:"success",steps:[{name:.value[1],status:"completed",conclusion:"success"}]}) |
          (if $mode == "missing" then map(select(.name != $target))
           elif $mode == "duplicate" then . + [(.[] | select(.name == $target) | .id = 999)]
           else map(if .name != $target then .
             elif $mode == "wrong_sha" then .head_sha = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
             elif $mode == "wrong_run" then .run_id = 124
             elif $mode == "wrong_name" then .name += " spoof"
             elif $mode == "wrong_step" then .steps[0].name += " spoof"
             elif $mode == "duplicate_step" then .steps += [.steps[0]]
             elif $mode == "missing_id" then del(.id)
             elif $mode == "step_failure" then .steps[0].conclusion = "failure"
             elif $mode == "in_progress" then .status = "in_progress" | .conclusion = null
             elif $mode != "" and $mode != "partial" then .conclusion = $mode
             else . end) end) |
          [{total_count:(length + (if $mode == "partial" then 1 else 0 end)),jobs:.}]'
    elif [[ "$1" == run && "$2" == download ]]; then
        mkdir -p dist
        cp "$SPLIT_FIXTURE/dist/"* dist/
        [[ ${RELAY_TAMPER:-0} == 0 ]] || printf tampered >> dist/sbom-core.cdx.json
    else
        exit 1
    fi
    exit
fi
if [[ "$*" == "attestation verify --help" ]]; then
    [[ ${GH_NO_ATTESTATION:-0} == 0 ]] || exit 1
    exit 0
fi
if [[ "$1" == workflow && "$2" == run ]]; then
    [[ "$*" == "workflow run provenance.yml --repo axiomasoft/azguard --ref release-candidate/v2.0.0-rc.1/123/2 -f candidate=$(git -C "$SPLIT_FIXTURE/work" rev-parse HEAD) -f run_id=123 -f run_attempt=2" ]]
    exit
fi
[[ ( $# == 13 || $# == 17 ) && "$1" == attestation && "$2" == verify && "$4" == --repo && "$6" == --signer-workflow && "$8" == --signer-digest && "${10}" == --source-digest && "${12}" == --source-ref ]] || exit 1
repo="${5#axiomasoft/}"
[[ "$repo" == azguard || "$repo" == azguard-filament ]] || exit 1
package=core
[[ "$repo" == azguard-filament ]] && package=filament
commit="$(git --git-dir="$SPLIT_FIXTURE/remotes/$repo.git" rev-parse 'refs/heads/release-candidate/v2.0.0-rc.1/123/2^{commit}')"
[[ "$6" == --signer-workflow && "$7" == "axiomasoft/$repo/.github/workflows/provenance.yml${BAD_SIGNER:-}" ]]
[[ "$9" == "$commit${BAD_COMMIT:-}" && "${11}" == "$commit${BAD_COMMIT:-}" ]]
[[ "${13:-}" == "refs/heads/release-candidate/v2.0.0-rc.1/123/2${BAD_REF:-}" ]]
if [[ $# == 17 ]]; then
    [[ "$repo" == azguard && "${14}" == --predicate-type && "${15}" == https://axiomasoft.github.io/azguard/attestations/release-artifact-relay/v1 && "${16}" == --format && "${17}" == json ]]
    [[ "$7" == "axiomasoft/$repo/.github/workflows/provenance.yml${BAD_FINAL_SIGNER:-}" ]]
    [[ "$9" == "$commit${BAD_FINAL_COMMIT:-}" && "${11}" == "$commit${BAD_FINAL_COMMIT:-}" ]]
    [[ "${13}" == "refs/heads/release-candidate/v2.0.0-rc.1/123/2${BAD_FINAL_REF:-}" ]]
    subject="$(basename "$3")"
    [[ "$(sha256sum "$3" | cut -d' ' -f1)" == "$(<"$SPLIT_FIXTURE/$subject.digest")${BAD_FINAL_DIGEST:-}" ]]
    jq -n --arg sha "${BAD_PREDICATE_SHA:-$(git -C "$SPLIT_FIXTURE/work" rev-parse HEAD)}" --arg run "${BAD_PREDICATE_RUN:-123}" --arg attempt "${BAD_PREDICATE_ATTEMPT:-2}" '[{verificationResult:{statement:{predicate:{candidate:$sha,run_id:$run,run_attempt:$attempt}}}}]'
else
    [[ "$(sha256sum "$3" | cut -d' ' -f1)" == "$(<"$SPLIT_FIXTURE/$package.digest")" ]]
fi
SH
# No real waiting is necessary in an offline negative fixture.
printf '#!/usr/bin/env bash\nexit 0\n' > "$tmp/bin/sleep"
chmod +x "$tmp/bin/gh" "$tmp/bin/sleep"
for package in core filament; do
    name=azguard
    [[ "$package" == filament ]] && name=azguard-filament
    printf '{"name":"axiomasoft/%s","description":"fixture","type":"library"}\n' "$name" > "$tmp/work/packages/$package/composer.json"
    printf '%s\n' "$package" > "$tmp/work/packages/$package/example.txt"
done
git -C "$tmp/work" init -q
git -C "$tmp/work" add packages
git -C "$tmp/work" commit -qm 'candidate'
sha="$(git -C "$tmp/work" rev-parse HEAD)"
git -C "$tmp/work" tag -a v2.0.0-rc.1 -m fixture
for package in core filament; do
    name=azguard
    [[ "$package" == filament ]] && name=azguard-filament
    split="$(git -C "$tmp/work" subtree split --prefix="packages/$package" "$sha" 2>/dev/null)"
    [[ "$package" != core ]] || core_split="$split"
    [[ "$package" != filament ]] || filament_split="$split"
    git init --bare -q "$tmp/remotes/$name.git"
    git -C "$tmp/work" push -q "$tmp/remotes/$name.git" "$split:refs/heads/release-candidate/v2.0.0-rc.1/123/2"
    cp -a "$tmp/work/packages/$package" "$tmp/package-$package"
    php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR); $j["version"]=$argv[2]; file_put_contents($p,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");' "$tmp/package-$package/composer.json" 2.0.0-rc.1
    mkdir "$tmp/archive-$package"
    composer archive --format=zip --dir="$tmp/archive-$package" --working-dir="$tmp/package-$package" --no-interaction >/dev/null
    archive=("$tmp/archive-$package/"*.zip)
    [[ ${#archive[@]} == 1 && -f ${archive[0]} ]]
    cp "${archive[0]}" "$tmp/dist/azguard-$package-2.0.0-rc.1.zip"
    sha256sum "$tmp/dist/azguard-$package-2.0.0-rc.1.zip" | cut -d' ' -f1 > "$tmp/$package.digest"
done
printf 'core sbom\n' > "$tmp/dist/sbom-core.cdx.json"
printf 'filament sbom\n' > "$tmp/dist/sbom-filament.cdx.json"
checksums() { (cd "$tmp/dist" && sha256sum azguard-core-2.0.0-rc.1.zip azguard-filament-2.0.0-rc.1.zip sbom-core.cdx.json sbom-filament.cdx.json > SHA256SUMS); }
checksums
restore_staging() {
    git --git-dir="$tmp/remotes/azguard.git" update-ref refs/heads/release-candidate/v2.0.0-rc.1/123/2 "$core_split"
    git --git-dir="$tmp/remotes/azguard-filament.git" update-ref refs/heads/release-candidate/v2.0.0-rc.1/123/2 "$filament_split"
}
verify() { (cd "$tmp/work" && bash "$verifier" "$sha" v2.0.0-rc.1 "$tmp/dist"); }
reject() {
    local expected="$1"
    if verify >"$tmp/output" 2>&1; then
        printf 'unexpected split verification success: %s\n' "$expected" >&2; exit 1
    fi
    if ! python3 -c 'import pathlib,sys; sys.exit(sys.argv[1] not in pathlib.Path(sys.argv[2]).read_text())' "$expected" "$tmp/output"; then
        printf 'missing refusal reason: %s\n' "$expected" >&2
        python3 -c 'import pathlib,sys; print(pathlib.Path(sys.argv[1]).read_text())' "$tmp/output" >&2
        exit 1
    fi
    if [[ ${EXPECT_STAGING_CLEANUP:-0} == 1 ]]; then
        for repo in azguard azguard-filament; do
            if git --git-dir="$tmp/remotes/$repo.git" show-ref --verify --quiet refs/heads/release-candidate/v2.0.0-rc.1/123/2; then
                printf 'failed provenance verification did not clean staging branch in %s\n' "$repo" >&2
                exit 1
            fi
        done
    fi
    restore_staging
}
export GH_NO_ATTESTATION=1
reject "GitHub CLI with 'gh attestation verify' support is required"
unset GH_NO_ATTESTATION
verify >"$tmp/output" 2>&1

for subject in sbom-core.cdx.json sbom-filament.cdx.json SHA256SUMS; do
    sha256sum "$tmp/dist/$subject" | cut -d' ' -f1 > "$tmp/$subject.digest"
done
export GITHUB_RUN_ID=123 GITHUB_RUN_ATTEMPT=2
verify_final() { (cd "$tmp/work" && bash "$verifier" "$sha" v2.0.0-rc.1 "$tmp/dist" --dispatch-final); }
verify_final >"$tmp/output" 2>&1
for variable in BAD_FINAL_SIGNER BAD_FINAL_COMMIT BAD_FINAL_REF BAD_FINAL_DIGEST BAD_PREDICATE_SHA BAD_PREDICATE_RUN BAD_PREDICATE_ATTEMPT; do
    export "$variable"=1
    if verify_final >"$tmp/output" 2>&1; then echo "final attestation accepted invalid $variable" >&2; exit 1; fi
    python3 -c 'import pathlib,sys; assert "public final artifact attestation verification failed: sbom-core.cdx.json" in pathlib.Path(sys.argv[1]).read_text()' "$tmp/output"
    for repo in azguard azguard-filament; do
        if git --git-dir="$tmp/remotes/$repo.git" show-ref --verify --quiet refs/heads/release-candidate/v2.0.0-rc.1/123/2; then
            echo "failed final provenance left staging branch in $repo" >&2
            exit 1
        fi
    done
    restore_staging
    unset "$variable"
done
for subject in sbom-core.cdx.json sbom-filament.cdx.json; do
    cp "$tmp/dist/$subject" "$tmp/original-$subject"
    printf 'tampered\n' >> "$tmp/dist/$subject"
    checksums
    if verify_final >"$tmp/output" 2>&1; then echo "tampered $subject accepted" >&2; exit 1; fi
    python3 -c 'import pathlib,sys; assert "public final artifact attestation verification failed" in pathlib.Path(sys.argv[1]).read_text()' "$tmp/output"
    restore_staging
    mv "$tmp/original-$subject" "$tmp/dist/$subject"
    checksums
done
printf '\n' >> "$tmp/dist/SHA256SUMS"
if verify_final >"$tmp/output" 2>&1; then echo 'tampered manifest accepted' >&2; exit 1; fi
python3 -c 'import pathlib,sys; assert "public final artifact attestation verification failed: SHA256SUMS" in pathlib.Path(sys.argv[1]).read_text()' "$tmp/output"
restore_staging
checksums

export BAD_SIGNER=-wrong EXPECT_STAGING_CLEANUP=1
reject 'public split attestation verification failed: core'
unset BAD_SIGNER EXPECT_STAGING_CLEANUP
export BAD_COMMIT=0 EXPECT_STAGING_CLEANUP=1
reject 'public split attestation verification failed: core'
unset BAD_COMMIT EXPECT_STAGING_CLEANUP
export BAD_REF=-wrong EXPECT_STAGING_CLEANUP=1
reject 'public split attestation verification failed: core'
unset BAD_REF EXPECT_STAGING_CLEANUP
# Changing ZIP container bytes while retaining the same files and updating the
# manifest must still fail the attestation subject digest verification.
python3 - "$tmp/dist/azguard-core-2.0.0-rc.1.zip" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'a') as archive:
    archive.comment = b'changed container'
PY
checksums
reject 'public split attestation verification failed: core'
cp "$tmp/archive-core/"*.zip "$tmp/dist/azguard-core-2.0.0-rc.1.zip"
checksums

# Run the real relay fetch step with a fake API and artifact download.
root="$(cd "$(dirname "$verifier")/.." && pwd)"
php -r 'require $argv[1]."/vendor/autoload.php"; $w=Symfony\Component\Yaml\Yaml::parseFile($argv[1]."/packages/core/.github/workflows/provenance.yml"); echo $w["jobs"]["relay-final-artifacts"]["steps"][0]["run"];' "$root" > "$tmp/relay-step.sh"
mkdir "$tmp/relay" "$tmp/relay-temp"
export FIXTURE_CANDIDATE="$sha" CANDIDATE="$sha" RELEASE_RUN_ID=123 RELEASE_RUN_ATTEMPT=2 GITHUB_REF=refs/heads/release-candidate/v2.0.0-rc.1/123/2 RUNNER_TEMP="$tmp/relay-temp" RELAY_FIXTURE=1
run_relay() { rm -rf "$tmp/relay/dist"; (cd "$tmp/relay" && bash "$tmp/relay-step.sh"); }
run_relay >"$tmp/output" 2>&1
for variable in RELAY_SHA RELAY_TAG RELAY_PATH RELAY_ATTEMPT RELAY_TAMPER; do
    case "$variable" in
        RELAY_SHA) export RELAY_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
        RELAY_TAG) export RELAY_TAG=v2.0.0 ;;
        RELAY_PATH) export RELAY_PATH=.github/workflows/other.yml ;;
        RELAY_ATTEMPT) export RELAY_ATTEMPT=1 ;;
        RELAY_TAMPER) export RELAY_TAMPER=1 ;;
    esac
    if run_relay >"$tmp/output" 2>&1; then echo "relay accepted invalid $variable" >&2; exit 1; fi
    unset "$variable"
done
for target in 'Validate before release' 'Consumer archives · L12' 'Consumer archives · L13' 'Release artifacts' 'Release evidence gate' 'Split validated packages / Split azguard' 'Split validated packages / Split azguard-filament'; do
    export RELAY_JOB_TARGET="$target"
    for mode in missing duplicate wrong_sha wrong_run wrong_name wrong_step duplicate_step missing_id step_failure in_progress failure skipped cancelled; do
        export RELAY_JOB_MODE="$mode"
        if run_relay >"$tmp/output" 2>&1; then echo "relay accepted $mode for $target" >&2; exit 1; fi
        python3 -c 'import pathlib,sys; assert "Private release prerequisites are missing, ambiguous, or unsuccessful" in pathlib.Path(sys.argv[1]).read_text()' "$tmp/output"
    done
done
export RELAY_JOB_MODE=partial
if run_relay >"$tmp/output" 2>&1; then echo 'relay accepted incomplete jobs page' >&2; exit 1; fi
unset RELAY_JOB_MODE RELAY_JOB_TARGET
export RELAY_RUN_STATUS=completed
if run_relay >"$tmp/output" 2>&1; then echo 'relay accepted unsuccessful completed run' >&2; exit 1; fi
unset RELAY_RUN_STATUS
unset RELAY_FIXTURE

# Exercise the real publication step against disposable local remotes. A bad
# second split must not publish even the first package tag.
php -r 'require $argv[1]."/vendor/autoload.php"; $w=Symfony\Component\Yaml\Yaml::parseFile($argv[1]."/.github/workflows/release.yml"); echo $w["jobs"]["publish-packages"]["steps"][0]["run"];' "$root" > "$tmp/publish-step.sh"
export GITHUB_REF_NAME=v2.0.0-rc.1 SPLIT_TOKEN=fixture CORE_SHA="$(git --git-dir="$tmp/remotes/azguard.git" rev-parse refs/heads/release-candidate/v2.0.0-rc.1/123/2)"
export FILAMENT_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
mkdir "$tmp/publish-invalid"
if RUNNER_TEMP="$tmp/publish-invalid" bash "$tmp/publish-step.sh" >"$tmp/output" 2>&1; then echo 'publication accepted wrong split SHA' >&2; exit 1; fi
for repo in azguard azguard-filament; do
    if git --git-dir="$tmp/remotes/$repo.git" show-ref --verify --quiet refs/tags/v2.0.0-rc.1; then echo 'tag published after failed prerequisite' >&2; exit 1; fi
done
export FILAMENT_SHA="$(git --git-dir="$tmp/remotes/azguard-filament.git" rev-parse refs/heads/release-candidate/v2.0.0-rc.1/123/2)"
# Reject the second push after the first succeeds, then exercise recovery.
printf '#!/bin/sh\nexit 1\n' > "$tmp/remotes/azguard-filament.git/hooks/pre-receive"
chmod +x "$tmp/remotes/azguard-filament.git/hooks/pre-receive"
mkdir "$tmp/publish-partial"
if RUNNER_TEMP="$tmp/publish-partial" bash "$tmp/publish-step.sh" >"$tmp/output" 2>&1; then echo 'publication ignored the second remote failure' >&2; exit 1; fi
[[ "$(git --git-dir="$tmp/remotes/azguard.git" rev-parse refs/tags/v2.0.0-rc.1)" == "$CORE_SHA" ]]
if git --git-dir="$tmp/remotes/azguard-filament.git" show-ref --verify --quiet refs/tags/v2.0.0-rc.1; then echo 'second tag unexpectedly exists' >&2; exit 1; fi
rm "$tmp/remotes/azguard-filament.git/hooks/pre-receive"
git -C "$tmp/work" push -q "$tmp/remotes/azguard-filament.git" "$sha:refs/tags/v2.0.0-rc.1"
mkdir "$tmp/publish-conflict"
if RUNNER_TEMP="$tmp/publish-conflict" bash "$tmp/publish-step.sh" >"$tmp/output" 2>&1; then echo 'publication accepted a conflicting existing tag' >&2; exit 1; fi
[[ "$(git --git-dir="$tmp/remotes/azguard.git" rev-parse refs/tags/v2.0.0-rc.1)" == "$CORE_SHA" ]]
git --git-dir="$tmp/remotes/azguard-filament.git" update-ref -d refs/tags/v2.0.0-rc.1
mkdir "$tmp/publish-valid"
RUNNER_TEMP="$tmp/publish-valid" bash "$tmp/publish-step.sh" >"$tmp/output" 2>&1
[[ "$(git --git-dir="$tmp/remotes/azguard.git" rev-parse refs/tags/v2.0.0-rc.1)" == "$CORE_SHA" ]]
[[ "$(git --git-dir="$tmp/remotes/azguard-filament.git" rev-parse refs/tags/v2.0.0-rc.1)" == "$FILAMENT_SHA" ]]
for repo in azguard azguard-filament; do
    if git --git-dir="$tmp/remotes/$repo.git" show-ref --verify --quiet refs/heads/release-candidate/v2.0.0-rc.1/123/2; then echo 'verified staging branch was not removed' >&2; exit 1; fi
done
# Restore fixture branches for the verifier's subsequent negative examples.
git --git-dir="$tmp/remotes/azguard.git" update-ref refs/heads/release-candidate/v2.0.0-rc.1/123/2 "$CORE_SHA"
git --git-dir="$tmp/remotes/azguard-filament.git" update-ref refs/heads/release-candidate/v2.0.0-rc.1/123/2 "$FILAMENT_SHA"

# A changed archive with the original manifest must fail the digest check.
printf 'tampered' > "$tmp/dist/azguard-core-2.0.0-rc.1.zip"
reject 'FAILED'
cp "$tmp/archive-core/"*.zip "$tmp/dist/azguard-core-2.0.0-rc.1.zip"

# A matching checksum is insufficient when the ZIP contains different bytes.
python3 - "$tmp/dist/azguard-core-2.0.0-rc.1.zip" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w') as archive:
    archive.writestr('example.txt', 'tampered')
PY
checksums
reject 'release archive does not match split tree'
cp "$tmp/archive-core/"*.zip "$tmp/dist/azguard-core-2.0.0-rc.1.zip"
checksums

# A changed split tree must fail even if the local candidate and ZIP are intact.
git -C "$tmp/work" push -q --force "$tmp/remotes/azguard.git" "$sha:refs/heads/release-candidate/v2.0.0-rc.1/123/2"
reject 'split tree mismatch: core'
# A tag pointing elsewhere must be rejected before any remote verification.
git -C "$tmp/work" commit --allow-empty -qm 'different tag target'
git -C "$tmp/work" tag -f -a v2.0.0-rc.1 -m fixture HEAD >/dev/null
git -C "$tmp/work" checkout -q --detach "$sha"
reject 'tag does not resolve to candidate SHA'

# Both independently split producers must use the same ZIP normalizer, and
# archive timestamps must not affect the subject digest.
cmp "$root/packages/core/.github/canonicalize-release-zip.py" "$root/packages/filament/.github/canonicalize-release-zip.py"
cmp "$root/packages/core/.github/workflows/provenance.yml" "$root/packages/filament/.github/workflows/provenance.yml"
mkdir "$tmp/second-archive"
touch -t 200101010000 "$tmp/package-core/example.txt"
composer archive --format=zip --dir="$tmp/second-archive" --working-dir="$tmp/package-core" --no-interaction >/dev/null
cp "$tmp/archive-core/"*.zip "$tmp/first.zip"
cp "$tmp/second-archive/"*.zip "$tmp/second.zip"
python3 "$root/packages/core/.github/canonicalize-release-zip.py" "$tmp/first.zip"
python3 "$root/packages/filament/.github/canonicalize-release-zip.py" "$tmp/second.zip"
cmp "$tmp/first.zip" "$tmp/second.zip"
printf 'split verification fixtures passed\n'

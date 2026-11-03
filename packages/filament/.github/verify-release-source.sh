#!/usr/bin/env bash
# Validate the private release identity and map its signed package tree to this split before signing.
set -Eeuo pipefail
[[ "${GITHUB_REF:-}" =~ ^refs/heads/release-candidate/(v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?)/([1-9][0-9]*)/([1-9][0-9]*)$ ]] || { echo 'invalid staging ref' >&2; exit 1; }
tag="${BASH_REMATCH[1]}" run_id="${BASH_REMATCH[3]}" attempt="${BASH_REMATCH[4]}"
case "${GITHUB_REPOSITORY:-}" in
  axiomasoft/azguard) package=core ;;
  axiomasoft/azguard-filament) package=filament ;;
  *) echo 'untrusted split repository' >&2; exit 1 ;;
esac
[[ "${GITHUB_SHA:-}" =~ ^[0-9a-f]{40}$ && "$(git rev-parse HEAD)" == "$GITHUB_SHA" ]] || { echo 'split checkout mismatch' >&2; exit 1; }
[[ -n "${GH_TOKEN:-}" && -n "${RELEASE_TAG_PUBLIC_KEY:-}" && "${RELEASE_TAG_SIGNER_FINGERPRINT:-}" =~ ^([0-9A-F]{40}|[0-9A-F]{64})$ ]] || { echo 'release identity prerequisites unavailable' >&2; exit 1; }
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
gh api "repos/axiomasoft/azguard-private/actions/runs/$run_id/attempts/$attempt" > "$work/run.json"
candidate="$(jq -er --arg tag "$tag" --argjson id "$run_id" --argjson attempt "$attempt" '
  select(.id == $id and .run_attempt == $attempt and .head_branch == $tag and .event == "push"
    and .repository.full_name == "axiomasoft/azguard-private"
    and (.path == ".github/workflows/release.yml" or .path == (".github/workflows/release.yml@" + $tag) or .path == (".github/workflows/release.yml@refs/tags/" + $tag))
    and (.status == "in_progress" or (.status == "completed" and .conclusion == "success")))
  | .head_sha | select(type == "string" and test("^[0-9a-f]{40}$"))' "$work/run.json")"
gh api --paginate --slurp "repos/axiomasoft/azguard-private/actions/runs/$run_id/attempts/$attempt/jobs?per_page=100" > "$work/jobs.json"
jq -e --arg sha "$candidate" --argjson run "$run_id" '
  (map(.jobs | length) | add) as $count |
  (map(.jobs[])) as $jobs |
  (length > 0 and all(.[]; .total_count == $count)) and
  ($jobs | all(.[]; (.id | type == "number" and . > 0)) and length == (map(.id) | unique | length)) and
  ([$jobs[] | select(.name == "Release evidence gate")] | . as $matches |
    length == 1 and ($matches[0] | .head_sha == $sha and .run_id == $run and .status == "completed" and .conclusion == "success" and
      ([.steps[]? | select(.name == "Evaluate release evidence")] | length == 1 and .[0].status == "completed" and .[0].conclusion == "success")))' "$work/jobs.json" >/dev/null || { echo 'release gate evidence missing or stale' >&2; exit 1; }
export GNUPGHOME="$work/gnupg"
mkdir -m 700 "$GNUPGHOME"
printf '%s\n' "$RELEASE_TAG_PUBLIC_KEY" | gpg --batch --quiet --import
printf '#!/bin/sh\nprintf "%%s\\n" "$GH_TOKEN"\n' > "$work/askpass"
chmod 700 "$work/askpass"
export GIT_ASKPASS="$work/askpass" GIT_TERMINAL_PROMPT=0
git init -q "$work/mono"
git -C "$work/mono" fetch --quiet --depth=1 "https://x-access-token@github.com/axiomasoft/azguard-private.git" "refs/tags/$tag:refs/tags/$tag"
[[ "$(git -C "$work/mono" cat-file -t "refs/tags/$tag")" == tag && "$(git -C "$work/mono" rev-parse "refs/tags/$tag^{commit}")" == "$candidate" ]] || { echo 'source tag mismatch' >&2; exit 1; }
git -C "$work/mono" verify-tag --raw "refs/tags/$tag" 2> "$work/status" || { echo 'source tag signature invalid' >&2; exit 1; }
mapfile -t signatures < <(awk '$1 == "[GNUPG:]" && $2 == "VALIDSIG" {print $3}' "$work/status")
[[ ${#signatures[@]} == 1 && "${signatures[0]}" == "$RELEASE_TAG_SIGNER_FINGERPRINT" ]] || { echo 'untrusted source signer' >&2; exit 1; }
[[ "$(git -C "$work/mono" rev-parse "$candidate:packages/$package")" == "$(git rev-parse 'HEAD^{tree}')" ]] || { echo 'split tree mismatch' >&2; exit 1; }
if [[ -n ${RUNNER_TEMP:-} ]]; then
    destination="$RUNNER_TEMP/verified-release-source"
    [[ ! -e "$destination" ]] || { echo 'verified source destination already exists' >&2; exit 1; }
    git clone -q --no-checkout --no-hardlinks "$work/mono" "$destination"
    git -C "$destination" checkout -q --detach "$candidate"
    [[ "$(git -C "$destination" rev-parse HEAD)" == "$candidate" ]] || exit 1
fi
printf 'Verified source tree for %s at %s and split %s.\n' "$package" "$candidate" "$GITHUB_SHA"

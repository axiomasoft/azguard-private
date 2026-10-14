#!/usr/bin/env bash
# Intentional-break drill of the compatibility comparison (I18, D26).
#
#   bash bin/compat-drill.sh [--baseline=<ref>] [--report=<file>]
#
# Clones the repository into a throwaway directory, tags the baseline (default HEAD) as v1.0.0 in the clone only, and
# for each case patches real source, regenerates the manifests or the format fixtures, commits and runs
# `php bin/api-compat.php --against=auto`. D1-D5 must be rejected with their rule id (exit 1), the control D6 must stay
# compatible (exit 0). The main repository's tags are asserted unchanged at the end. Exit 0 only when every case
# behaved as expected; 2 on usage or setup errors. `--report` writes the evidence table as Markdown. PHP is `$PHP`
# (default `php`).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php="${PHP:-php}"
baseline='HEAD'
report=''

for argument in "$@"; do
    case "$argument" in
        --baseline=?*) baseline="${argument#--baseline=}" ;;
        --report=?*) report="${argument#--report=}" ;;
        *) echo "usage: bash bin/compat-drill.sh [--baseline=<ref>] [--report=<file>]" >&2; exit 2 ;;
    esac
done

if [[ -n "$report" && "$report" != /* ]]; then
    report="$PWD/$report"
fi

sha="$(git -C "$root" rev-parse --verify --quiet "${baseline}^{commit}")" || { echo "unknown baseline ${baseline}" >&2; exit 2; }
tags_before="$(git -C "$root" for-each-ref --format='%(refname) %(objectname)' refs/tags)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
clone="$work/repo"

git clone --quiet --no-local "$root" "$clone"
git -C "$clone" fetch --quiet "$root" "$sha" 2>/dev/null || true
git -C "$clone" checkout --quiet --detach "$sha" || { echo "baseline ${sha} is not reachable in a clone" >&2; exit 2; }
git -C "$clone" config user.name 'AzGuard compatibility drill'
git -C "$clone" config user.email 'drill@example.invalid'

# vendor/ as hard links: the path-repository symlinks must be relative so that they resolve to the clone's packages/.
cp -al "$root/vendor" "$clone/vendor"
for link in "$clone"/vendor/axiomasoft/*; do
    target="$(readlink "$link")"
    if [[ "$target" == /* ]]; then
        echo "vendor link ${link#"$clone"/} is absolute (${target}); the drill would patch the main checkout" >&2
        exit 2
    fi
done

# Only the drill's own tag is a stable tag in the clone, so `--against=auto` selects it.
git -C "$clone" tag --list 'v[1-9]*' | xargs -r git -C "$clone" tag -d >/dev/null
git -C "$clone" tag v1.0.0

# Edits one file of the clone with a PHP callback over its content; fails when the content does not change.
edit() {
    local file="$clone/$1" code="$2"
    "$php" -r '
        $file = $argv[1];
        $before = (string) file_get_contents($file);
        $after = (function (string $s): string { '"$code"' })($before);
        if ($after === $before) { fwrite(STDERR, "patch did not apply to {$file}\n"); exit(1); }
        file_put_contents($file, $after);
    ' "$file"
}

regenerate_manifests() {
    (cd "$clone" && "$php" bin/api-manifest.php --write >/dev/null)
}

regenerate_formats() {
    (cd "$clone" && AZGUARD_FORMATS_WRITE=1 "$php" -d memory_limit=1G vendor/bin/pest tests/Regression/Formats \
        tests/Feature/Console/CommandsSnapshotTest.php tests/Feature/Configuration/ConfigKeysTest.php >/dev/null 2>&1) || true
}

failures=0
rows=()

# run_case <id> <description> <expected exit> <expected rule or -> <changed path>
run_case() {
    local id="$1" description="$2" expected_exit="$3" rule="$4" changed="$5" output exit found

    if git -C "$clone" diff --quiet -- "$changed"; then
        echo "${id} FAIL: the patch did not change ${changed}" >&2
        failures=$((failures + 1))
        rows+=("| ${id} | ${description} | ${rule} | — | not regenerated |")
        git -C "$clone" reset --quiet --hard v1.0.0 && git -C "$clone" clean -fdq
        return
    fi

    git -C "$clone" add -A
    git -C "$clone" commit --quiet -m "drill: ${id}"
    set +e
    output="$(cd "$clone" && "$php" bin/api-compat.php --against=auto 2>&1)"
    exit=$?
    set -e

    found='—'
    if [[ "$rule" != '-' ]] && grep -q "^${rule//./\\.} " <<<"$output"; then
        found="$rule"
    fi

    if [[ "$exit" == "$expected_exit" && ( "$rule" == '-' || "$found" == "$rule" ) ]]; then
        echo "${id} ok: exit ${exit}${rule:+ ${rule}} (${description})"
        rows+=("| ${id} | ${description} | ${rule} | ${exit} | ok |")
    else
        echo "${id} FAIL: exit ${exit}, expected ${expected_exit} ${rule} (${description})" >&2
        printf '%s\n' "$output" | head -20 >&2
        failures=$((failures + 1))
        rows+=("| ${id} | ${description} | ${rule} | ${exit} | FAIL |")
    fi
    if [[ "$exit" == 1 ]]; then
        printf '%s\n' "$output" | grep -v '^[0-9]* compatibility break' | sed "s/^/    ${id} /"
    fi

    git -C "$clone" reset --quiet --hard v1.0.0
    git -C "$clone" clean -fdq
}

# D1: a method added to an spi-implement interface (and implemented by the package's own plugins so that they load).
edit packages/core/src/Contracts/Plugins/Plugin.php '
    return preg_replace("/\n}\s*$/", "\n\n    public function boot(): void;\n}\n", $s, 1);'
for implementer in $(cd "$clone" && grep -rlE '^final class \w+ implements Plugin$' packages/core/src packages/core/modules); do
    edit "$implementer" '
        return preg_replace("/\n}\s*$/", "\n\n    public function boot(): void {}\n}\n", $s, 1);'
done
regenerate_manifests
run_case D1 'method boot() added to the spi-implement interface AzGuard\Contracts\Plugins\Plugin' 1 implement.method_added packages/core/api-manifest.json

# D2: a parameter of a public AzGuard\AzGuard method renamed.
edit packages/core/src/AzGuard.php '
    $start = strpos($s, "public function check(");
    $end = strpos($s, "\n    }\n", $start);
    $method = substr($s, $start, $end - $start);
    return substr($s, 0, $start).str_replace("\$subject", "\$who", $method).substr($s, $end);'
regenerate_manifests
run_case D2 'parameter $subject of AzGuard\AzGuard::check() renamed to $who' 1 parameter.renamed packages/core/api-manifest.json

# D3: a case added to a closed enum.
edit packages/core/src/Tenancy/TenantMode.php '
    return str_replace("    case Required = '"'"'required'"'"';\n", "    case Required = '"'"'required'"'"';\n    case Optional = '"'"'optional'"'"';\n", $s);'
regenerate_manifests
run_case D3 'case Optional added to the closed enum AzGuard\Tenancy\TenantMode' 1 enum.closed_case_added packages/core/api-manifest.json

# D4: an event payload key renamed in the event's toArray().
edit packages/core/src/Events/RoleGranted.php '
    return str_replace("'"'"'role'"'"' => \$this->role->full(),", "'"'"'role_key'"'"' => \$this->role->full(),", $s);'
regenerate_formats
run_case D4 'payload key role of role.granted renamed to role_key' 1 format.removed tests/Fixtures/Formats/events.json

# D5: the core migration renamed.
git -C "$clone" mv packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php \
    packages/core/database/migrations/2026_10_01_000001_create_azguard_tables.php
regenerate_formats
run_case D5 'core migration 2026_10_01_000000_create_azguard_storage renamed' 1 format.removed tests/Fixtures/Formats/resources.json

# D6 (control): a public method added to a final api class.
edit packages/core/src/Changes/GrantFilter.php '
    return preg_replace("/\n}\s*$/", "\n\n    public function isUnbounded(): bool\n    {\n        return false;\n    }\n}\n", $s, 1);'
regenerate_manifests
run_case D6 'public method isUnbounded() added to the final api class AzGuard\Changes\GrantFilter' 0 - packages/core/api-manifest.json

tags_after="$(git -C "$root" for-each-ref --format='%(refname) %(objectname)' refs/tags)"
if [[ "$tags_before" != "$tags_after" ]]; then
    echo "the main repository's tags changed" >&2
    failures=$((failures + 1))
    tag_verdict='changed'
else
    echo "main repository tags unchanged"
    tag_verdict='unchanged'
fi

if [[ -n "$report" ]]; then
    {
        echo "| Case | Break | Expected rule | Exit | Verdict |"
        echo "|:--|:--|:--|:--|:--|"
        printf '%s\n' "${rows[@]}"
        echo
        echo "Baseline \`${baseline}\` = \`${sha}\` tagged v1.0.0 in a throwaway clone; main repository tags ${tag_verdict}."
    } > "$report"
fi

exit $((failures > 0 ? 1 : 0))

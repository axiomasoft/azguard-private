#!/usr/bin/env bash
# Isolated commit-tree fixtures for the read-only release preflight.
set -euo pipefail

script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/release-preflight.sh"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT

git -C "$fixture" init --quiet
git -C "$fixture" config user.name 'AzGuard fixture'
git -C "$fixture" config user.email 'fixture@example.invalid'

expect_pass() {
    (cd "$fixture" && bash "$script" "$1" HEAD >/dev/null)
}

expect_fail() {
    if (cd "$fixture" && bash "$script" "$1" HEAD >/dev/null 2>&1); then
        echo "unexpected preflight pass for version $1" >&2
        exit 1
    fi
}

cat > "$fixture/CHANGELOG.md" <<'EOF'
# Changelog

## [Unreleased]

## [1.0.0] - 2026-09-23

### Changed

- Candidate release.
EOF
git -C "$fixture" add CHANGELOG.md
git -C "$fixture" commit --quiet -m 'fixture: matching candidate'
expect_pass 1.0.0
expect_fail 1.0.1
expect_fail Unreleased

# A working-tree edit cannot change the candidate commit result.
sed -i 's/\[1.0.0\]/[1.0.1]/' "$fixture/CHANGELOG.md"
expect_pass 1.0.0
expect_fail 1.0.1

cat > "$fixture/CHANGELOG.md" <<'EOF'
# Changelog

```markdown
## [1.0.0] - 2026-09-23
```

### ## [1.0.0]
## [1.0.0] - wrong-date
EOF
git -C "$fixture" add CHANGELOG.md
git -C "$fixture" commit --quiet -m 'fixture: malformed headings'
expect_fail 1.0.0

cat > "$fixture/CHANGELOG.md" <<'EOF'
# Changelog

## [1.0.0-rc.1] - 2026-09-23
EOF
git -C "$fixture" add CHANGELOG.md
git -C "$fixture" commit --quiet -m 'fixture: prerelease heading'
expect_pass 1.0.0-rc.1
expect_fail 1.0.0

git -C "$fixture" rm --quiet CHANGELOG.md
git -C "$fixture" commit --quiet -m 'fixture: missing changelog'
expect_fail 1.0.0

# Release notes are the body of the version section, nothing before or after it.
notes_script="$(dirname "$script")/release-notes.sh"
cat > "$fixture/CHANGELOG.md" <<'EOF'
# Changelog

## [Unreleased]

- Next.

## [1.1.0] - 2026-10-01

### Added

- Feature.

```markdown
## [1.0.0] - fenced
```

## [1.0.0] - 2026-09-23

## [0.9.0] - 2026-09-01

- Old.
EOF
git -C "$fixture" add CHANGELOG.md
git -C "$fixture" commit --quiet -m 'fixture: release notes'
notes="$(cd "$fixture" && bash "$notes_script" 1.1.0 HEAD)"
expected="$(printf '%s\n' '### Added' '' '- Feature.' '' '```markdown' '## [1.0.0] - fenced' '```')"
if [[ "$notes" != "$expected" ]]; then
    printf 'unexpected release notes:\n%s\n' "$notes" >&2
    exit 1
fi
for empty in 1.0.0 2.0.0; do
    if (cd "$fixture" && bash "$notes_script" "$empty" HEAD >/dev/null 2>&1); then
        echo "unexpected release notes for $empty" >&2
        exit 1
    fi
done

# Commit headers.
commits_script="$(dirname "$script")/check-commits.sh"
bash "$commits_script" --header 'feat(core): add a wildcard depth limit' 'fix!: drop the legacy cache key' 'build(deps): bump pest' >/dev/null
for bad in 'docs(readme): README для 1.0' 'Feat: add x' 'feat: add x.' 'wip' 'feat(Core): add x' 'feature: add x' "feat: $(printf 'x%.0s' {1..100})"; do
    if bash "$commits_script" --header "$bad" >/dev/null 2>&1; then
        echo "unexpected commit header pass: $bad" >&2
        exit 1
    fi
done

echo 'release-preflight fixtures: PASS'

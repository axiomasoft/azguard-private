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

echo 'release-preflight fixtures: PASS'

# Contributing to AzGuard

Thank you for your interest! This guide explains how to contribute effectively.

## Branching Strategy

| Branch pattern | Purpose |
|---|---|
| `main` | Stable, always releasable |
| `feat/sprint-N-*` | Sprint feature work |
| `fix/*` | Bug fixes |
| `hotfix/*` | Critical production fixes |
| `docs/*` | Documentation only |
| `ci/*` | CI/CD changes |

## Commit Convention

All commits **must** follow [Conventional Commits](https://www.conventionalcommits.org/).
This drives automatic CHANGELOG generation and SemVer tagging.

```
<type>(<scope>): <description>

[optional body]

[optional footer(s)]
```

**Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `ci`, `chore`, `revert`

**Examples:**
```
feat(authorizer): add wildcard permission depth limit
fix(scopes): correct scoped role priority resolution order
docs(guide): add entity-scopes quick-start example
test(unit): add HasAzGuard::assignScopedRole coverage
ci(matrix): add PHP 8.4 to test matrix
```

**Breaking changes:**
```
feat(roles)!: rename resolvePermission to permissions

BREAKING CHANGE: The method `resolvePermission()` has been renamed to `permissions()`.
Update all custom role classes accordingly.
```

## Release Process

Releases are fully automated via GitHub Actions.

1. Merge all sprint PRs into `main`
2. Create and push a SemVer tag:
   ```bash
   git tag v1.2.0 -m "Release v1.2.0"
   git push origin v1.2.0
   ```
3. GitHub Actions automatically:
   - Runs the full test suite
   - Generates CHANGELOG entry from commits
   - Creates a GitHub Release with release notes
   - Updates `CHANGELOG.md` and commits it back to `main`

## Version Guidelines

| Change | Version bump |
|---|---|
| Breaking API change | Major (`v1.0.0 → v2.0.0`) |
| New feature, backward-compatible | Minor (`v1.0.0 → v1.1.0`) |
| Bug fix | Patch (`v1.0.0 → v1.0.1`) |
| Pre-release | Suffix (`v1.1.0-beta.1`) |

## Development Setup

```bash
git clone git@github.com:axioma-studio/azguard.git
cd azguard
composer install

# Run all checks
composer test          # Pest tests
composer analyse       # Larastan
composer lint:check    # Pint style check
composer refactor:check # Rector dry-run
```

## Pull Request Requirements

- [ ] Tests pass (`composer test`)
- [ ] Coverage ≥ 85% (`composer test:coverage`)
- [ ] No Larastan errors (`composer analyse`)
- [ ] Code style clean (`composer lint:check`)
- [ ] PR title follows Conventional Commits format
- [ ] Description explains the *why*, not just the *what*

# Releasing AzGuard

AzGuard is developed as a monorepo and published as two standalone Composer
packages:

| Package | Path | Packagist |
| --- | --- | --- |
| `axiomasoft/azguard` | `packages/core` | panel-based authorization core |
| `axiomasoft/azguard-filament` | `packages/filament` | Filament editors |

## Release notes: the curated CHANGELOG

The `CHANGELOG.md` section of a version is the only source of release notes. Commit
messages are checked for the Conventional Commits format (`bin/check-commits.sh`, see
[CONTRIBUTING.md](CONTRIBUTING.md)) and suggest the SemVer bump, but they are not
copied into the notes: a commit log mixes internal work (tests, CI, refactoring) with
user-visible changes, and one change often spans several commits. Each pull request
with a user-visible change edits `[Unreleased]`; the release moves it under the version.

## Versioning: lockstep

Both packages share **one version**. A release tags the monorepo and
splits the same tag into every package repo, so `azguard` and
`azguard-filament` always advance together.

Consequences:

- The Filament package requires the core as `"axiomasoft/azguard": "self.version"`.
- A breaking change in any package bumps the major for both.
- Never tag a single package independently — always tag the monorepo.

The package `composer.json` files intentionally carry **no `version` field**:
Composer derives the version from the git tag the split action pushes.

## Cutting a release

1. Ensure the declared PHP/Laravel/Testbench matrix and the dedicated SQLite,
   PostgreSQL, MySQL, and Redis CI lanes are green for the candidate commit.
   Run `composer check`, `composer test:pgsql`, `composer test:mysql`, and
   `composer test:redis` against isolated local services before approval. A
   missing service or skipped check is an incomplete qualification, not green.
2. Decide the SemVer version from the actual API/default/schema delta. Move the
   reviewed release notes from `[Unreleased]` into a `## [x.y.z] - YYYY-MM-DD` section
   in the root `CHANGELOG.md` in the **candidate commit** (not after the tag).
   Update the root path-repository versions, the root `^x.y` requirements,
   the Filament package's `self.version` core requirement, and both `dev-main`
   aliases together; validate every manifest and resolve both packages
   before making the candidate commit.
3. Verify the candidate tree read-only:
   ```bash
   bash bin/release-preflight.sh 1.0.0 HEAD
   ```
   The script reads `git show REF:CHANGELOG.md`; a working-tree-only edit fails.
   Run `bash bin/test-release-preflight.sh` for isolated matching, missing,
   malformed, prerelease, and working-tree-only fixtures.
4. After explicit owner approval, create and push an annotated tag from that
   commit:
   ```bash
   git tag -a v1.0.0 -m "v1.0.0 — release summary"
   git push origin v1.0.0
   ```
5. CI takes over:
   - **`release.yml`** repeats `release-preflight` on the tagged commit, validates
     every package manifest (`composer validate --strict`), re-runs analyse + style +
     tests, then creates the GitHub release. Its notes are the version's CHANGELOG
     section, printed by `bin/release-notes.sh x.y.z REF` (try it before tagging).
   - **`split.yml`** is called only after release validation succeeds. Its split
     job is skipped until its one-time setup is complete.

There is **no** post-tag workflow that commits `CHANGELOG.md` back to `main`; the
tagged commit must already contain the version section.

Local resolution of the `self.version` and `^1.0` constraints between packages is handled by the
`versions` map in the root `composer.json` path repository — keep it in sync with
the current major when bumping (e.g. `2.0.0` after a `v2.0.0`).

## Split and Packagist status — 2026-07-22

Split repositories and Packagist publication are deferred until the one-time setup below
is done. Release tags still create a GitHub Release; the `split` job is disabled by
default through the repository variable guard.

## One-time setup (before enabling split)

1. Create two empty repos under the `axiomasoft` org: `azguard` and
   `azguard-filament`. They are **read-only mirrors** — never push to them by hand.
2. Create a fine-grained PAT (or a deploy setup) with push access to both and
   add it as the `MONOREPO_SPLIT_TOKEN` repository secret.
3. Submit each mirror to [Packagist](https://packagist.org/packages/submit) and
   enable the GitHub service hook so new tags auto-update.
4. Create the repository variable `SPLIT_ENABLED=true` only after steps 1–3 are
   complete; this enables the guarded `split` job on future release tags.

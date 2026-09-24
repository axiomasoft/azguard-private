# Releasing AzGuard

AzGuard is developed as a monorepo and published as three standalone Composer
packages:

| Package | Path | Packagist |
| --- | --- | --- |
| `axioma-studio/azguard-core` | `packages/core` | core RBAC engine |
| `axioma-studio/azguard-filament` | `packages/filament` | Filament admin UI |
| `axioma-studio/azguard-context` | `packages/context` | multi-workspace context |

## Versioning: lockstep

All three packages share **one version**. A release tags the monorepo and
splits the same tag into every package repo, so `azguard-core`,
`azguard-filament` and `azguard-context` always advance together.

Consequences:

- Satellites require the core at the **same major**: `"axioma-studio/azguard-core": "^0.3"`.
- A breaking change in any package bumps the major for all three.
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
   both satellite packages' core requirements, and all three `dev-main`
   aliases together; validate every manifest and resolve the three packages
   before making the candidate commit.
3. Verify the candidate tree read-only:
   ```bash
   bash bin/release-preflight.sh 0.4.0 HEAD
   ```
   The script reads `git show REF:CHANGELOG.md`; a working-tree-only edit fails.
   Run `bash bin/test-release-preflight.sh` for isolated matching, missing,
   malformed, prerelease, and working-tree-only fixtures.
4. After explicit owner approval, create and push an annotated tag from that
   commit:
   ```bash
   git tag -a v0.4.0 -m "v0.4.0 — release summary"
   git push origin v0.4.0
   ```
5. CI takes over:
   - **`release.yml`** repeats `release-preflight` on the tagged commit, validates
     every package manifest (`composer validate --strict`), re-runs analyse + style +
     tests, then creates the GitHub release (release notes body still comes from
     `git-cliff` for the tag).
   - **`split.yml`** is called only after release validation succeeds. Its split
     job is skipped until its one-time setup is complete.

There is **no** post-tag workflow that commits `CHANGELOG.md` back to `main`; the
tagged commit must already contain the version section.

Local resolution of the `^0.3` constraints between packages is handled by the
`versions` map in the root `composer.json` path repository — keep it in sync with
the current major when bumping (e.g. `2.0.0` after a `v2.0.0`).

## Split and Packagist status — 2026-07-22

Split repositories and Packagist publication are deferred for the private monorepo
(D25). Release tags still create a GitHub Release; the `split` job is disabled by
default through the repository variable guard.

## One-time setup (before enabling split)

1. Create three empty repos under the org: `azguard-core`, `azguard-filament`,
   `azguard-context`. They are **read-only mirrors** — never push to them by hand.
2. Create a fine-grained PAT (or a deploy setup) with push access to all three and
   add it as the `MONOREPO_SPLIT_TOKEN` repository secret.
3. Submit each mirror to [Packagist](https://packagist.org/packages/submit) and
   enable the GitHub service hook so new tags auto-update.
4. Create the repository variable `SPLIT_ENABLED=true` only after steps 1–3 are
   complete; this enables the guarded `split` job on future release tags.

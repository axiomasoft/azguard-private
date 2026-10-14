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

1.0.0 is the first public release. From 1.0, versions follow SemVer: a breaking change of a supported contract bumps
the major version of both packages. Mutation testing is deferred by the owner and does not block 1.0.0.

Both packages share **one version**. A release tags the monorepo and
splits the same tag into every package repo, so `azguard` and
`azguard-filament` always advance together.

Consequences:

- The Filament package requires the core as `"axiomasoft/azguard": "self.version"`.
- A breaking change in any package bumps the major for both.
- Never tag a single package independently — always tag the monorepo.

The package `composer.json` files intentionally carry **no `version` field**:
Composer derives the version from the git tag the split action pushes.

## Compatibility baseline

From 1.0 the supported surface is compared with a baseline on every push, pull request and release tag
(`.github/workflows/compatibility.yml`): `composer api:check` first (the manifests are current), then
`composer api:compat` (`php bin/api-compat.php --against=auto`). The comparison reads the artifacts listed in the root
`compatibility.json` — the attribute manifests of both packages and the format fixtures (exceptions, events, journal
rows, resources, configuration defaults, commands, doctor, explain, serializable types, DDL of every driver) — from the
baseline commit with `git show` and never loads baseline code. A break exits 1 with one line per rule
(`<rule-id> <artifact-id> <locator>: <baseline> -> <current>`); additions are listed and allowed.

The baseline is:

- **before the first stable tag** — the `candidate` sha in `compatibility.json`. The release that freezes a candidate
  sets it in a commit that changes only `compatibility.json` (a commit cannot name itself);
- **afterwards** — the newest tag `vX.Y.Z` with X ≥ 1 and no pre-release suffix that is merged into the compared commit.
  The pointer is then no longer read, and a pull request cannot change a tag.

A pull request that changes `compatibility.json` fails the `compat-baseline` guard
(`bin/compat-baseline-guard.sh`, fixtures in `bin/test-compat-baseline-guard.sh`) unless it carries the label
**`compat-baseline`**: replacing the baseline is an explicit release decision, never a side effect. Run
`composer api:compat` locally before tagging; `bash bin/compat-drill.sh` proves on real patches in a throwaway clone
that the comparison rejects the intentional breaks (D1–D5) and accepts the control (D6).

Recommended repository settings (owner): require the `Compatibility` check and the other CI checks on `main` in branch
protection, and restrict who may apply the `compat-baseline` label.

## Cutting a release

1. Ensure the declared PHP/Laravel/Testbench matrix and the dedicated SQLite,
   PostgreSQL, MySQL, and Redis CI lanes are green for the candidate commit.
   The last `tests-nightly.yml` run (scale tests and all parity seeds on every server)
   must be green for the candidate commit too: trigger it with `workflow_dispatch` when
   the schedule has not run on it. Run `composer check`,
   `composer test:db -- pgsql --parallel --full`, the same for `mysql` and `mariadb`,
   `composer test:db -- <driver> --engines` and `composer test:redis` against isolated
   local services before approval. A missing service or skipped check is an incomplete
   qualification, not green.

   Run the load bench on the same host as the reference and compare. The recorded
   profile of the 1.0 code is `bench/results/2026-10-14-1.0-<driver>-<cache>-mini.json` for
   `sqlite/none`, `sqlite/array`, `pgsql/none`, `pgsql/redis`, `mysql/none` and `mariadb/none`
   (see `bench/README.md`). For each pair run
   `php -d opcache.enable_cli=1 bench/bin/run.php --tier=mini --driver=<driver> --cache=<cache> --out=<new>`
   with nothing else running on the host, then
   `php bench/bin/compare.php bench/results/2026-10-14-1.0-<driver>-<cache>-mini.json <new>.json`.
   A run with a failed correctness check or an engine error blocks the release. A reported
   regression needs a fix or a sentence in the release notes. Numbers of another host are not
   comparable: if the host changed, record a new reference first.
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
   git tag -a v1.0.0 -m "AzGuard 1.0.0"
   git push origin v1.0.0
   ```
5. CI takes over. **`release.yml`** runs the whole qualification graph on the tagged commit, in the same run:
   - the called workflows `tests.yml` (PHP × Laravel × stability matrix, database lanes and engines, replica, Redis,
     coverage, consumer archives), `static-analysis.yml` (PHPStan, type coverage), `code-style.yml` (Pint, Rector),
     `docs.yml` (lint and build; a call never deploys), `tests-nightly.yml` (scale tests, all parity seeds on every
     server) and `compatibility.yml` (manifest freshness and the comparison with the baseline);
   - `validate`: every package manifest (`composer validate --strict`), the `self.version` pin, the core, core +
     Filament, core-only archives and the Audit relocation rehearsal in clean Laravel 13 apps stamped with the tag's
     version, and `release-preflight` on the tagged commit.

   **`split.yml`** is called only after all of them succeed; its jobs fail if the one-time setup is incomplete. The
   GitHub release needs the whole graph and both splits. Its notes are the version's CHANGELOG section, printed by
   `bin/release-notes.sh x.y.z REF` (try it before tagging). The workflow has no manual trigger, and a pre-release tag
   (`vX.Y.Z-*`) runs the same graph.

There is **no** post-tag workflow that commits `CHANGELOG.md` back to `main`; the
tagged commit must already contain the version section.

Local resolution of the `self.version` and `^1.0` constraints between packages is handled by the
`versions` map in the root `composer.json` path repository — keep it in sync with
the current release when bumping (for 1.0.0, both aliases are `1.0.x-dev`).

## Split and Packagist status — 2026-10-10

Split repositories and Packagist publication are deferred until the one-time setup below
is done. GitHub Release creation requires successful splits of both packages. Missing
`SPLIT_ENABLED=true` or `MONOREPO_SPLIT_TOKEN` fails publishing; it does not block a local annotated tag.

## One-time setup (before enabling split)

1. Create two empty repos under the `axiomasoft` org: `azguard` and
   `azguard-filament`. They are **read-only mirrors** — never push to them by hand.
2. Create a fine-grained PAT (or a deploy setup) with push access to both and
   add it as the `MONOREPO_SPLIT_TOKEN` repository secret.
3. Submit each mirror to [Packagist](https://packagist.org/packages/submit) and
   enable the GitHub service hook so new tags auto-update.
4. Create the repository variable `SPLIT_ENABLED=true` only after steps 1–3 are
   complete; this enables the guarded `split` job on future release tags.

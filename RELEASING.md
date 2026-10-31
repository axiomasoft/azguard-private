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

The historical changelog calls 1.0.0 the first public release, but that statement alone does not establish publication
or consumption. Treat 1.0 as potentially consumed. The read-only [publication inventory](build/release/publication-inventory.json)
records observations and access failures separately; an inaccessible private API or a 404 does not prove absence.
The planned line is **2.0.0-rc.1**, then **2.0.0** for both packages because Unreleased contains breaking changes.
The release SHA is chosen during P8 qualification after the code is complete. Existing tags must not be moved or rewritten.

On 2026-10-30 the accessible origin advertised only `v0.3.0` and `v0.7.0`, and its GitHub releases API returned
one release, `v0.3.0`. Both split tag queries required unavailable credentials; their release APIs returned 404.
Packagist's stable and development metadata endpoints returned 404 for both package names, so no versions or
source/dist references were returned. These observations do not prove that 1.0 was never published or consumed.
The inventory is a timestamped local build artifact; retain it with the release evidence.

The root `composer.lock` is the development and release lock: qualification jobs `composer install` from it,
packages never ship it, and version-range jobs re-resolve with `composer update`.

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

## Repository settings

The expected settings live in `.github/rulesets/` and `.github/CODEOWNERS`; `bin/repository-settings-audit.php` compares them
with the live repository and only reads. Applying them is an owner act.

```bash
# create (first time)
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/main.json
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/tags.json
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/tag-creation.json
# update an existing ruleset
gh api -X PUT repos/axiomasoft/azguard-private/rulesets/<id> --input .github/rulesets/<file>.json
# private vulnerability reporting, secret scanning and push protection
gh api -X PUT repos/axiomasoft/azguard-private/private-vulnerability-reporting
gh api -X PATCH repos/axiomasoft/azguard-private --input - <<'JSON'
{"security_and_analysis": {"secret_scanning": {"status": "enabled"}, "secret_scanning_push_protection": {"status": "enabled"}}}
JSON
# audit
php bin/repository-settings-audit.php --live
```

`main` allows no bypass actor; only the repository admin role may create `v*` tags, and no one may update or delete them.
The `compat-baseline` label stays a triage-role decision (single maintainer).

**Break-glass.** The owner temporarily sets `enforcement` to `disabled` on the `main` ruleset, performs the action,
restores `active`, and records the date, the reason, the ruleset JSON before and after, and the commit in the plan's
`artifacts/evidence/governance/break-glass.md`. `php bin/repository-settings-audit.php --live` must be green again before
any tag.

## Schema changes

A migration that was published is never edited or removed: `tests/Arch/MigrationsImmutableTest.php` compares every
migration file with the compatibility baseline (the newest stable tag, before it the `candidate` of
`compatibility.json`). A schema change is a new migration file, together with an updated DDL artifact of
`compatibility.json`.

Before a schema-changing release, run the upgrade fixture on all four drivers:

```bash
base=$(python3 -c 'import json; print(json.load(open("compatibility.json"))["candidate"])')   # or the last stable tag
for driver in sqlite pgsql mysql mariadb; do bash bin/upgrade-fixture.sh --from="$base" --to=HEAD --driver="$driver"; done
```

`bin/upgrade-fixture.sh` installs the older archive in a clean Laravel application, seeds grants, captures the decisions
(`azguard:explain --json`) and the doctor, swaps to the newer archive, runs `php artisan migrate`, and fails when a
decision or the doctor changed. It also reports whether `migrate:rollback` succeeds on the new schema; a migration that
cannot be rolled back is declared in `UPGRADING.md`.

## After the tag: verify the split and Packagist

The split repositories are checked against the monorepo tag, then the published packages are installed in clean
applications:

```bash
bash bin/split-verify.sh v1.0.0-rc.1            # tree identity per package, Packagist reference and core requirement
bash bin/consumer-fixture.sh --source=packagist --version=1.0.0-rc.1 --laravel=12 --with-filament
bash bin/consumer-fixture.sh --source=packagist --version=1.0.0-rc.1 --laravel=13 --with-filament
```

`bin/split-verify.sh` compares `packages/<dir>` of the monorepo tag with the tree of the split tag (extra paths of the
split side must be listed in `.github/split-allowlist.txt`, empty by default), prints the mapping
`monorepo <sha> -> split <sha>`, and checks that Packagist lists the version from the split commit and that the Filament
package requires the same core version. Packagist indexes a tag some minutes after the push: an unreachable or stale
document is exit `3` or `1`, never green; retry later. `bash bin/test-split-verify.sh` proves the script offline.

## Release artifacts

The `artifacts` job of `release.yml` runs `bin/release-artifacts.sh <version> <outdir>`: the two package archives
(`azguard-core-<v>.zip`, `azguard-filament-<v>.zip`), a CycloneDX SBOM of each package's production dependencies
(`sbom-core.cdx.json`, `sbom-filament.cdx.json`, made with the pinned `syft`) and `SHA256SUMS`. The job attests the build
provenance of the four files and the release attaches all five to the GitHub Release. Verify a download:

```bash
sha256sum -c SHA256SUMS
gh attestation verify azguard-core-<v>.zip --repo axiomasoft/azguard-private
```

The same script builds the files locally (`bash bin/release-artifacts.sh 1.0.0 /tmp/out`).

## Critical mutation

`composer mutate:critical` (`bin/mutation-gate.sh critical`) mutates the decision kernel, the authorization pipeline,
the query witnesses, the cache and state tokens, the change transaction and the Filament guards, and requires every
covered mutant to be killed. It is release-blocking: `release.yml` calls `mutation.yml` with `package: critical`, and
`split` and `release` need it. A survivor is fixed by a test or marked `// @pest-mutate-ignore -- <reason>` as a proven
equivalent mutant. It also runs weekly. It needs a coverage driver (pcov or Xdebug).

## Dependency updates

- A high or critical security update (Composer, npm, OSV) is triaged within 1 business day; an update forced by an
  advisory is its own commit touching only `composer.lock` / `package-lock.json`.
- An ordinary Dependabot update is reviewed within a week; superseded Dependabot PRs are closed.
- Major updates are never auto-merged.
- An OSV ignore needs `ignoreUntil` and a reason in `.github/osv-scanner.toml`.

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
   bash bin/release-preflight.sh 2.0.0 HEAD
   ```
   The script reads `git show REF:CHANGELOG.md`; a working-tree-only edit fails.
   A pre-release `1.0.0-rc.1` is served by its own `## [1.0.0-rc.1]` section, else by the `## [1.0.0]` section: one
   section serves the release candidates and the stable tag of the same commit (`bash bin/release-preflight.sh
   1.0.0-rc.1 HEAD`, `bash bin/release-notes.sh 1.0.0-rc.1 HEAD`).
   The `validate` job of `release.yml` also requires that the tag is annotated, that the tagged commit is reachable from
   `main`, and, for the first stable tag, that the commit changes only `compatibility.json` over the candidate.
   Run `bash bin/test-release-preflight.sh` for isolated matching, missing,
   malformed, prerelease, and working-tree-only fixtures.
   For the first stable release the qualified candidate C is not the commit that is tagged: a commit cannot name
   itself, so the next commit C+1 sets `candidate` in `compatibility.json` to C and changes nothing else
   (`git diff --name-only C C+1 -- . ':!plans'` prints only `compatibility.json`). Run `composer api:compat`,
   `bash bin/compat-drill.sh --baseline=C` and `bash bin/release-preflight.sh 2.0.0 HEAD` on C+1 and wait for its CI
   before tagging. The release run on the tag compares C+1 with C: a tag on the compared commit is never its own
   baseline.
4. After explicit owner approval, create and push an annotated tag from the commit named above (for a first stable tag, C+1; for
   later releases, the candidate commit itself):
   ```bash
   git tag -a v2.0.0 <qualified-commit> -m "AzGuard 2.0.0"
   git push origin v2.0.0
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

Local resolution of the `self.version` and `^2.0` constraints between packages is handled by the
`versions` map in the root `composer.json` path repository — keep it in sync with
the current release line when bumping (both aliases are `2.0.x-dev`).

## Split and Packagist status — 2026-10-10

The status recorded on 2026-10-10 was that split repositories and Packagist publication were deferred until the
one-time setup below was done; consult the dated inventory for subsequent observations. GitHub Release creation requires successful splits of both packages. Missing
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

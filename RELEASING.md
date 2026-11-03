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
or consumption. Treat 1.0 as potentially consumed. The read-only local publication inventory
records observations and access failures separately; an inaccessible private API or a 404 does not prove absence.
The planned line is **2.0.0-rc.1**, then **2.0.0** for both packages because Unreleased contains breaking changes.
The release SHA is chosen during P8 qualification after the code is complete. Existing tags must not be moved or rewritten.

On 2026-10-30 the accessible origin advertised only `v0.3.0` and `v0.7.0`, and its GitHub releases API returned
one release, `v0.3.0`. Both split tag queries required unavailable credentials; their release APIs returned 404.
Packagist's stable and development metadata endpoints returned 404 for both package names, so no versions or
source/dist references were returned. These observations do not prove that 1.0 was never published or consumed.
The inventory is a timestamped, ignored local build artifact, not a repository link.
Retain a copy with the release evidence supplied for approval; a fresh clone does not contain it.

The root `composer.lock` is the development and release lock: qualification jobs `composer install` from it,
packages never ship it, and version-range jobs re-resolve with `composer update`.

Both packages share **one version**. A successful release tags the monorepo and
publishes the same version tag in both package repositories. Publication is sequential,
so a failed release can temporarily leave only one package at that version; see recovery below.

Consequences:

- The Filament package requires the core as `"axiomasoft/azguard": "self.version"`.
- A breaking change in any package bumps the major for both.
- Never tag a single package independently — always tag the monorepo.

The package `composer.json` files intentionally carry **no `version` field**:
Composer derives the version from the package tag published after provenance succeeds.

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

### Security channel and publication inventory 2026-11-03

Read-only verification found the following; retain the timestamped observations with release evidence:

- The GitHub connector reports `axiomasoft/azguard` as private and the connected account as an administrator.
  Anonymous requests to its intended `https://github.com/axiomasoft/azguard/security/advisories/new` and
  private-reporting status API returned HTTP 404. The connector does not expose that setting; the local `gh`
  client has no credentials (exit 4). Enablement/public reporter access is unverified. Do not infer it from admin access.
- The public monorepo `axiomasoft/azguard-private` exposes `SECURITY.md` (HTTP 200), tags `v0.3.0` and `v0.7.0`,
  and one non-draft/non-prerelease release, `v0.3.0`. Both split tag/release APIs and both packages' stable/dev
  Packagist metadata returned HTTP 404; the connector could access the private core split but not the Filament split.
  This does not establish published 1.x or stable 2.x artifacts and does not prove non-publication/non-consumption.
- The canonical private contact is [dv.vostrikov@gmail.com](mailto:dv.vostrikov@gmail.com), matching both Composer
  author records. Gmail MX resolution succeeded; no mailbox delivery or vulnerability submission was tested.
  Both `support.security` fields link to the verified public monorepo policy, not an invisible advisory form.
- The docs site `https://axiomasoft.github.io/azguard-private/` returned HTTP 200; project and origin-root
  `security.txt` URLs returned HTTP 404. The project asset is prepared for a later authorized deployment, not a
  verified live endpoint. The project repository cannot serve the origin root; see [hosting status](docs/security.md#security-contact-hosting-status).

Before each release, refresh the actual publication inventory, verify the reporting channel from a researcher's
access level without submitting a report, and check the hosted `security.txt` content and expiry. Keep email
canonical unless the intended public repository's private reporting is confirmed enabled. Settings changes,
deployment and publication require their own authorization; the commands below are not grants.

The [Security Policy](SECURITY.md) supports the latest stable 2.x minor once published and `main` on a best-effort
basis. No published 1.x inventory or LTS commitment is established; newly verified 1.x artifacts require an explicit
support decision. An acknowledgement within 3 business days is a target, not an SLA. Apply its severity-based
patch/prerelease and coordinated disclosure process, including the [key compromise response](docs/security.md#key-compromise-response).

### Applying repository settings (owner only)

The expected settings live in `.github/rulesets/` and `.github/CODEOWNERS`; `bin/repository-settings-audit.php` compares them
with the live repository and only reads. Applying them is an owner act.

```bash
# create (first time)
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/main.json
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/tags.json
gh api -X POST repos/axiomasoft/azguard-private/rulesets --input .github/rulesets/tag-creation.json
# update an existing ruleset
gh api -X PUT repos/axiomasoft/azguard-private/rulesets/<id> --input .github/rulesets/<file>.json
# secret scanning and push protection
gh api -X PATCH repos/axiomasoft/azguard-private --input - <<'JSON'
{"security_and_analysis": {"secret_scanning": {"status": "enabled"}, "secret_scanning_push_protection": {"status": "enabled"}}}
JSON
# audit
php bin/repository-settings-audit.php --live
```

Private vulnerability reporting is available for public repositories. Only after separate owner authorization and
confirmation that the intended `axiomasoft/azguard` repository is public, enable its private reporting, verify
`GET /repos/axiomasoft/azguard/private-vulnerability-reporting` returns `enabled: true` with the required permissions,
and verify the researcher-facing form. Until then use the email in `SECURITY.md`.

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
bash bin/split-verify.sh v2.0.0-rc.1            # tree identity per package, Packagist reference and core requirement
bash bin/consumer-fixture.sh --source=packagist --version=2.0.0-rc.1 --laravel=12 --with-filament
bash bin/consumer-fixture.sh --source=packagist --version=2.0.0-rc.1 --laravel=13 --with-filament
```

`bin/split-verify.sh` compares `packages/<dir>` of the monorepo tag with the tree of the split tag (extra paths of the
split side must be listed in `.github/split-allowlist.txt`, empty by default), prints the mapping
`monorepo <sha> -> split <sha>`, and checks that Packagist lists the version from the split commit and that the Filament
package requires the same core version. Packagist indexes a tag some minutes after the push: an unreachable or stale
document is exit `3` or `1`, never green; retry later. `bash bin/test-split-verify.sh` proves the script offline.

## Release artifacts

**Release archive guarantee:** the explicitly published core and Filament ZIP assets have checksums, validated source
and resolved-dependency SBOMs, and a verified monorepo-to-split-to-ZIP attestation chain. GitHub's automatically
generated source ZIP/tar.gz snapshots are separate files, not the signed release assets; GitHub may change their
compressed bytes while preserving contents. Packagist watches the public split repositories, and Composer's GitHub VCS
driver can deliver a GitHub-generated zipball rather than the signed release ZIP. The release-asset byte attestation
does not authenticate that Composer zipball; verify the split tag/tree and the downloaded archive separately. See
[GitHub archive stability](https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives#stability-of-source-code-archives)
and [Composer VCS repositories](https://github.com/composer/composer/blob/main/doc/05-repositories.md#vcs).

For a downloaded provider-generated ZIP or tar.gz, a local comparison is available:

```bash
python3 bin/provider-archive-evidence.py --repository /path/to/repository --commit <full-commit-sha> \
  --archive /path/to/snapshot.zip --kind zip --out build/provider-snapshot
```

Use `--kind tar.gz` for a gzip tarball. The tool checks the exported Git tree, file hashes, executable bits and
symlinks without extracting the untrusted archive, validates a CycloneDX source inventory, and copies the exact bytes
with a checksum file into a new output directory. It rejects duplicate or unsafe paths and limits compressed input to
256 MiB. Its mapping is explicitly `nonrelease-unsigned`: it does not authenticate the provider, resolve consumer
dependencies or establish that a future download has the same digest. Existing output files are never overwritten.
This local comparison does not prove the provider's identity or turn a GitHub snapshot into a signed release asset.

**Publication also requires verified setup:** the split job checks the reviewed setup request, live settings and fresh
permission and attestation probes before pushing staging refs. Missing credentials, inaccessible APIs, stale probes
and unavailable attestation entitlement fail the job. The old final-artifact relay remains disabled. Local artifacts
are nonrelease evidence and do not provide signatures or qualification.

`php bin/package-evidence.php --local-mapping MONOREPO SPLIT core|filament TAG VERSION ZIP SOURCE_SBOM OUTPUT`
rebuilds the ZIP members from the tagged split tree after the explicit Composer version transform. Its record is
`nonrelease`, with no signature or CI qualification. It checks neither a signed tag nor a dependency graph; verify an
annotated signed tag separately with `git verify-tag`. `php bin/verify-release-evidence.php --candidate-source SHA
RUN-ID-ATTEMPT FILE` checks the internal consistency of an already collected release-gate record; it does not authenticate
that record's origin. `php bin/prepare-release-setup.php` creates `build/release/setup-request.json` with dated
read-only observations and the proposed settings and payload hashes. The proposal does not authorize remote changes.
`php bin/verify-release-evidence.php --setup=build/release/setup-request.json --read-only --strict` uses live read-only APIs, a settings
audit and cryptographically verified probes; it needs no candidate SHA and never dispatches workflows or writes remote.
The legacy `--setup [REQUEST]` form remains supported; both forms are always strict and read-only.
`--candidate-artifacts SHA TAG DIST CORE-SHA FILAMENT-SHA RUN-ID-ATTEMPT` checks the signed tag, complete artifact
set, mapping, schemas and trusted attestations. It requires the configured tag key, fingerprint and a `gh` supporting
attestation verification. A split tag is recorded as expected until its remote target is checked after publication.

The release workflow and both public split producers require `RELEASE_TAG_PUBLIC_KEY` and
`RELEASE_TAG_SIGNER_FINGERPRINT` repository variables.
They must contain the owner-approved public key and the uppercase fingerprint of the actual signing key or subkey;
the workflow imports the key into a temporary keyring and rejects an unsigned tag or a different signer. Provisioning
these variables requires the separate setup gate. Tag signature verification is independent of ZIP provenance.

The `artifacts` job of `release.yml` runs `bin/release-artifacts.sh <version> <outdir>`: the two package archives
(`azguard-core-<v>.zip`, `azguard-filament-<v>.zip`), a CycloneDX source file inventory for each ZIP
(`source-core.cdx.json`, `source-filament.cdx.json`), and an SBOM for each independently resolved consumer graph
(`sbom-core.cdx.json`, `sbom-filament.cdx.json`, made with the pinned `syft`). The latter describes the specific
resolved graph, not all possible consumer installations. All four SBOMs must pass the pinned CycloneDX schema validator,
and `SHA256SUMS` includes both ZIPs, all four SBOMs, `resolution-core.lock` / `resolution-filament.lock` and the
two `mapping-*.json` files. Each resolved SBOM is compared with its audited consumer lock; the source inventory is
checked against actual ZIP bytes. Each mapping binds the source tree, explicit Composer transform, expected split
commit and tag, ZIP, SBOMs, lock, workflow SHA and run attempt. The release job verifies the actual ZIP's public
attestations against the split repository, workflow, staging branch, split commit and each predicate type, including
exact comparison of both SBOM predicates and the mapping predicate. The staging branch is
`release-candidate/<tag>/<run-id>/<attempt>`. Each production resolution and each test matrix lock must also pass OSV
before release qualification.

Before its ZIP build the split producer verifies the signed source tag, the completed release evidence gate and the
exact monorepo package tree against its checkout. It rebuilds its own canonical ZIP, checks the downloaded artifact,
mapping, lock and both schema-valid SBOMs, then attests that ZIP separately with provenance, each SBOM and the mapping.
It needs `PRIVATE_RELEASE_READ_TOKEN` with read access to source contents and Actions in each split repository, plus
the owner-approved tag key and signer fingerprint there. Each split setup probe uses that token to read source
metadata, Actions and `packages/core/composer.json`. For a private source, missing Contents access fails the probe;
for a public source, a successful content read does not prove the token has private Contents permission. The probe
records whether the source was public or private and the verifier requires that result to match its current visibility;
after a visibility change, rerun both probes. A previous successful probe does not qualify a changed workflow payload.
Real-run qualification is still required.
The two repositories cannot be updated atomically: a failed second tag push can leave one package published even though
the release job fails. Packagist may pick up that package before the other tag exists. Do not describe this state as a
successful release, delete or overwrite a public tag, or assume the first push was rolled back.

### Recovery from a partial package tag push

For a partial publication between `axiomasoft/azguard` and `axiomasoft/azguard-filament`, preserve the failed run and attempt, candidate SHA, verified split SHAs and logs. Inspect
both public tag targets with `git ls-remote --refs --tags https://github.com/axiomasoft/<repo>.git refs/tags/<tag>`
for `<repo>` equal to `azguard` and `azguard-filament`, and compare each existing target with the corresponding
verified split SHA from the failed run. Investigate any conflicting target with the owner; the workflow rejects it.
After the cause is resolved and the required authorization is in place, rerun **all jobs** for the same release run,
not just the failed job: each attempt must produce its own complete gate and provenance evidence. The idempotent publication
step (`publish-packages`) checks each existing tag SHA against the newly verified split commit, accepts a matching tag, and pushes any missing tag.
A retry can fail again; it does not make the two remote updates atomic. Check both tag targets and the final release
job after the retry.

After both tags are verified, the workflow deletes the staging branches using commit-specific leases. A failed run can
leave staging branches; inspect and remove those only after preserving any required evidence and confirming their run
and commit, with the required separate authorization.
The public workflow must already exist on the split repository default branch for GitHub workflow dispatch. Provisioning
these permissions and secrets, and qualifying the flow in a real run, require separately authorized setup. No private
attestation entitlement is assumed. Verify downloads with a `gh` version supporting attestations:

```bash
sha256sum -c SHA256SUMS
gh attestation verify azguard-core-<v>.zip --repo axiomasoft/azguard --signer-workflow axiomasoft/azguard/.github/workflows/provenance.yml --signer-digest <split-commit> --source-digest <split-commit> --source-ref refs/heads/release-candidate/v<version>/<run-id>/<attempt> --predicate-type https://slsa.dev/provenance/v1
gh attestation verify azguard-core-<v>.zip --repo axiomasoft/azguard --signer-workflow axiomasoft/azguard/.github/workflows/provenance.yml --signer-digest <split-commit> --source-digest <split-commit> --source-ref refs/heads/release-candidate/v<version>/<run-id>/<attempt> --predicate-type https://cyclonedx.org/bom --format json
gh attestation verify azguard-core-<v>.zip --repo axiomasoft/azguard --signer-workflow axiomasoft/azguard/.github/workflows/provenance.yml --signer-digest <split-commit> --source-digest <split-commit> --source-ref refs/heads/release-candidate/v<version>/<run-id>/<attempt> --predicate-type https://axiomasoft.github.io/azguard/attestations/package-mapping/v1 --format json
```

The same script builds a local nonrelease rehearsal (`bash bin/release-artifacts.sh 2.0.0-rc.1 /tmp/out`) from
committed `HEAD`, even if the worktree has changes. Its mapping reports that SHA and must not be used to qualify
uncommitted work. The release workflow additionally rejects modified or untracked package input.

To exercise the current worktree without network resolution, run
`php bin/package-evidence.php --source=worktree --version=2.0.0-rc.1 --out=build/package-evidence --offline-fixture`.
The output directory must be empty and the pinned CycloneDX validator must already be cached and hash-verified. The
command compares two independent ZIP builds from each copied worktree snapshot, records the explicit `.github`
exclusion, validates source and synthetic fixture SBOMs and writes checksums. Its `evidence.json` is `nonrelease`, has
no attestation or split mapping, and labels the dependency graph synthetic: no consumer resolution or audit ran.

## Dependency updates

- A high or critical security update (Composer, npm, OSV) is prioritized for triage under the best-effort
  [Security Policy](SECURITY.md), with no separate guaranteed deadline; an update forced by an
  advisory is its own commit touching only `composer.lock` / `package-lock.json`.
- An ordinary Dependabot update is reviewed within a week; superseded Dependabot PRs are closed.
- Dependabot auto-merge is disabled until a required eligibility check on the updated PR has been proven. All
  Dependabot updates require normal review and manual merge. The workflow disables an existing auto-merge request
  when it handles a same-repository Dependabot PR, including an auto-merge-enabled event. A scheduled and manually dispatched sweep also attempts to disable
  existing requests on open Dependabot PRs, including forks. Neither establishes branch protection. Scheduled runs can
  be delayed, fail, or never run before an already armed PR merges; the event-driven job can also lose that race, and API failure leaves requests armed. Before deployment
  and release, inspect all open Dependabot PRs and have an authorized owner disable any existing requests immediately.
  Monitor both reconciliation jobs and resolve failures; a green run does not prove that a concurrent request is absent.
- Before release, run `php bin/repository-settings-audit.php --live` and verify the active ruleset for the target
  branch requires the intended tests and security checks, applies to Dependabot and has no bypass for it. Before
  enabling auto-merge in the future, prove on an updated PR that a required eligibility check runs on its current
  head and blocks merging until auto-merge has been reconciled. The `pull_request_target` workflow runs against the
  base branch, so do not assume its job provides that head check. Inspect the
  live required check names and their producing workflows; a successful auto-merge job is not evidence that tests passed.
  Missing, inactive, bypassable or incomplete rules block release until the owner resolves them. This read-only audit
  is part of P8.1; do not change remote settings without owner authorization.
- An OSV ignore needs `ignoreUntil` and a reason in `.github/osv-scanner.toml`.

## Cutting a release

### Production operations acceptance

Before qualifying a release candidate, retain
`bash bin/production-runbook-drill.sh --fixture=disposable --report=build/runbooks/drill.json` evidence for the exact tree:
source SHA plus any working diff, PHP/Laravel/Testbench/Pest and Redis image versions, command exits, restore/reset,
rotation, denial and monitoring fixture results. The drill owns a disposable SQLite `*_test.sqlite` database and
a network-disabled Redis container; it never accepts production endpoints. `--self-test` is local array-store
evidence only. Missing Docker/PhpRedis/image access, skipped fixtures, timeout or nonzero exits are incomplete
qualification. The coordinator's final qualification runs the required closure/matrix on the final candidate.

For each host deployment, the deployment record must name the responsible people and link their actual evidence:

| Owner | Required evidence before traffic |
|---|---|
| Application/deployment | Exact SHA/lock/config/build ID; all panels, storages, namespaces/generations and signing key IDs; worker restart inventory; primary allow/deny smoke checks and production Doctor output with warning dispositions |
| Database/recovery | Compatible restore-tested backup ID/hash, recovery point and authority/audit inventory; traffic/writer fence; successful reset/new incarnation and epoch for every affected panel; rehearsed rollback pair |
| Platform/Redis | Dedicated store/credentials and environment prefix/namespace; finite TTL; TLS certificate validation, ACL own-prefix/foreign-prefix probes and network isolation results; bounded retirement inventory if old persistent entries remain |
| Monitoring/on-call | Installed adapter/exporter and alert rule revisions; observed contract and transient signals, scrape/telemetry absence checks; redacted notification receipt with receiver and timestamps |
| Security/release | Signing rotation/compromise owner, retired-key rejection and current verification-key inventory; separate owner authorization for remote settings, token registration and exact publication destinations |

Use [Operations](docs/advanced/operations.md), [Security](docs/advanced/security.md#operational-key-compromise) and
[Observability](docs/advanced/observability.md#operator-monitoring-acceptance). A successful local drill does not prove
production Redis isolation, backup recoverability on a different engine or host alert delivery. Record those as
pending until the owners supply evidence. A failed reset, wrong configuration or failed smoke check keeps the host
maintenance fence closed. This release checklist does not itself authorize a production restore or remote change.

### Candidate qualification and publication

The release evidence collection, split-tree mapping and final provenance path require qualification in a real workflow
run and independent review before publication. A successful local fixture or build is not release qualification.

1. Ensure the declared PHP/Laravel/Testbench matrix and the dedicated SQLite,
   PostgreSQL, MySQL, and Redis CI lanes are green for the candidate commit.
   The last `tests-nightly.yml` run (scale tests and all parity seeds on every server)
   must be green for the candidate commit too: trigger it with `workflow_dispatch` when
   the schedule has not run on it. Run `composer check`,
   `composer test:db -- pgsql --parallel --full`, the same for `mysql` and `mariadb`,
   `composer test:db -- <driver> --engines` and `composer test:redis` against isolated
   local services before approval. A missing service or skipped check is an incomplete
   qualification, not green.

   Run the load bench on the same host for a baseline and candidate, with the same driver, cache,
   tier, dataset and tool versions (see `bench/README.md`). For each pair run
   `php -d opcache.enable_cli=1 bench/bin/run.php --tier=mini --driver=<driver> --cache=<cache> --out=<baseline>`
   on the baseline and the same command with `--out=<candidate>` on the candidate, with nothing
   else running on the host. Compare with `php bench/bin/compare.php <baseline>.json <candidate>.json`.
   The historical 1.0 results lack the metadata needed for this comparison. A failed correctness
   check, engine error or stable matched regression blocks qualification; unstable results do not
   establish a latency verdict. If the host changed, record a new baseline first.
2. Decide the SemVer version from the actual API/default/schema delta. Move the
   reviewed release notes from `[Unreleased]` into a `## [x.y.z] - YYYY-MM-DD` section
   in the root `CHANGELOG.md` in the **candidate commit** (not after the tag).
   Update the root path-repository versions, the root `^x.y` requirements,
   the Filament package's `self.version` core requirement, and both `dev-main`
   aliases together; validate every manifest and resolve both packages
   before making the candidate commit.
3. Verify the candidate tree read-only:
   ```bash
   bash bin/release-preflight.sh 2.0.0-rc.1 HEAD
   ```
   The script reads `git show REF:CHANGELOG.md`; a working-tree-only edit fails.
   A pre-release `2.0.0-rc.1` is served by its own `## [2.0.0-rc.1]` section, else by the `## [2.0.0]` section: one
   section serves the release candidates and the stable tag of the same commit (`bash bin/release-preflight.sh
   2.0.0-rc.1 HEAD`, `bash bin/release-notes.sh 2.0.0-rc.1 HEAD`).
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
4. After explicit owner approval, publish the planned release candidate from the qualified candidate C:
   ```bash
   git tag -s v2.0.0-rc.1 <qualified-candidate-C> -m "AzGuard 2.0.0-rc.1"
   git push origin v2.0.0-rc.1
   ```
   The subsequent stable release needs its own qualification and approval. For a first stable tag,
   use the baseline-only commit C+1 described above, never C:
   ```bash
   git tag -s v2.0.0 <qualified-baseline-commit-C+1> -m "AzGuard 2.0.0"
   git push origin v2.0.0
   ```
   If a stable baseline tag already exists, tag the qualified candidate itself instead.
5. CI takes over. **`release.yml`** runs the whole qualification graph on the tagged commit, in the same run:
   - the called workflows `tests.yml` (PHP × Laravel × stability matrix, database lanes and engines, replica, Redis,
     coverage, consumer archives), `static-analysis.yml` (PHPStan, type coverage), `code-style.yml` (Pint, Rector),
     `docs.yml` (lint and build; a call never deploys), `tests-nightly.yml` (scale tests, all parity seeds on every
     server) and `compatibility.yml` (manifest freshness and the comparison with the baseline);
   - `validate`: every package manifest (`composer validate --strict`), the `self.version` pin, the core, core +
     Filament, core-only archives and the Audit relocation rehearsal in clean Laravel 13 apps stamped with the tag's
     version, and `release-preflight` on the tagged commit.

   **`split.yml`** is called only after all of them succeed; it pushes staging branches, not package tags. Its jobs
   fail if the one-time setup is incomplete. After split verification and attestations, the publication job checks both
   staged commits again and pushes their package tags. A failure between the two tag pushes can leave a partial
   publication and requires owner-directed recovery; tags are never overwritten. The
   GitHub release needs the whole graph and both splits. Its notes are the version's CHANGELOG section, printed by
   `bin/release-notes.sh x.y.z REF` (try it before tagging). The workflow has no manual trigger, and a pre-release tag
   (`vX.Y.Z-*`) runs the same graph.

There is **no** post-tag workflow that commits `CHANGELOG.md` back to `main`; the
tagged commit must already contain the version section.

Local resolution of the `self.version` and `^2.0` constraints between packages is handled by the
`versions` map in the root `composer.json` path repository — keep it in sync with
the current release line when bumping (both aliases are `2.0.x-dev`). The local
map's stable `2.0.0` identity exercises monorepo development resolution; it does
not attest installation of `2.0.0-rc.1`. Release validation separately resolves
the tag-stamped archives, including prereleases.

## Split and Packagist status — 2026-10-10

The status recorded on 2026-10-10 was that split repositories and Packagist publication were deferred until the
one-time setup below was done; consult the dated inventory for subsequent observations. GitHub Release creation requires successful splits of both packages. Missing
`SPLIT_ENABLED=true` or `MONOREPO_SPLIT_TOKEN` fails publishing; it does not block a local annotated tag.

## One-time setup (before enabling split)

1. Run `php bin/prepare-release-setup.php` and review the generated request, its observed unknowns, exact repository
   names, ruleset bodies, workflow hashes, environment payload and token scopes. The proposed release reviewer is
   `@academici` (user ID `3863273`), taken from `.github/CODEOWNERS`; the owner must approve the actor and confirm its
   effective permissions. Choose the trusted public tag-signing key and its actual signing-key or subkey fingerprint.
   The request is local and grants nothing.
2. Only after separate owner authorization, configure the public owned `axiomasoft/azguard` and
   `axiomasoft/azguard-filament` mirrors, release environment, rulesets, `SPLIT_ENABLED`, variables, workflows and the
   three scoped secrets exactly as reviewed. `MONOREPO_SPLIT_TOKEN` needs contents and Actions write on both split
   repositories; `PRIVATE_RELEASE_READ_TOKEN` needs contents and Actions read on the source in each split repository;
   `RELEASE_SETUP_READ_TOKEN` is a source-repository secret with the read-only scopes listed in the request. Store no
   token values in the request or logs. Submit the mirrors to [Packagist](https://packagist.org/packages/submit) and
   configure webhooks only under the publication setup approval.
3. After explicit authorization for the probe, dispatch `release-setup-probe.yml` on source `main` and approve its
   release environment. It writes unique empty `release-setup-probe/<run>/<attempt>` branches to each split, verifies
   and deletes them with commit-specific leases, and dispatches each public attestation probe on `main`. An interrupted
   or failed cleanup can leave a probe branch: preserve the run and inspect the exact ref before any authorized cleanup.
4. Wait for all three probe runs to succeed, regenerate the local request and run `php bin/verify-release-evidence.php
   --setup build/release/setup-request.json`. This verifier performs only reads; it verifies current payloads, the
   environment, token metadata, a clean settings audit, recent complete probe jobs and signed public canaries with
   pinned repository, workflow, ref, digest and predicate. Proofs older than one hour or for a different default-branch
   commit are rejected. Select a candidate SHA only after this succeeds; refresh and rerun the authorized probes if
   the default branches change or the proof expires before split. Each later release still needs its own qualification
   and publication authorization.

# Release evidence

The current work does not publish or split either package. The core-only receipt format described below is retained
for local evidence and a separately authorized future release; it does not establish a current publication plan.
Each release candidate has an evidence file, `release-evidence/v<version>.yml`, at the root of the repository (outside
`packages/`, so it is never part of a split archive). It binds one commit to the workflow runs, local runs and artifacts
that qualified it. `bin/release-evidence.php` validates it, offline for the shape and with `--live` against GitHub.
Use `urn:azguard:release-evidence:2` for core-only evidence. It requires `artifacts.core_archive`,
`artifacts.sbom_core`, `split.core`, and a nonempty `packagist.core`; it rejects the Filament archive, SBOM,
split and Packagist fields. The historical
`urn:azguard:release-evidence:1` continues to require both packages; do not fabricate Filament evidence.

## Core-only example

Every value below is a placeholder.

```yaml
schema: urn:azguard:release-evidence:2
version: 1.0.0-rc.1
commit: 0000000000000000000000000000000000000000        # the commit the tag points to
candidate: 0000000000000000000000000000000000000000     # the qualified candidate
created_at: 2026-01-01T00:00:00Z
toolchain:
  php: [8.3.x, 8.4.x, 8.5.x]
  laravel: [13.x]
  composer: <version>
  node: <version>
  databases: {pgsql: <version>, mysql: <version>, mariadb: <version>, redis: <version>}
workflows:                    # every value: https://github.com/axiomasoft/azguard-private/actions/runs/<id>
  release: <url>
  tests: <url>
  nightly: <url>
  compatibility: <url>
  static: <url>
  style: <url>
  docs: <url>
  security: <url>
  zizmor: <url>
local:
  - {command: <command>, exit: 0, summary: <counts>, log: <path>}
artifacts: {core_archive: <sha256>, sbom_core: <sha256>}
split:
  core: {repo: axiomasoft/azguard, tag_sha: <40-hex>, tree_identical: true}
packagist: {core: <version seen>}
benchmarks: {reference_host: <id>, comparison: <path>}
settings_audit: <path to the output of repository-settings-audit --live --json>
owner_approval: {actor: <github login>, timestamp: <UTC>}   # optional until the release decision
```

## What the validator rejects

- a version that is not SemVer, a `commit` or `candidate` that is not 40 hex characters;
- a workflow URL outside the repository, or a run whose commit is not the evidence `commit` (`--live`);
- a `local` row with a non-zero exit, a split with `tree_identical: false`;
- a missing workflow, or mixed commits: a change after qualification needs a new run and a new file.

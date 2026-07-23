---
name: release-engineering
bucket: oss-dev
version: 0.1.0
description: SemVer strategy, CHANGELOG, release pipeline (CI/CD), pre-releases, deprecation policy for an OSS package
risk: draft
persona: oss-dev
tags: [oss, semver, release, changelog, ci]
requires: [oss-development]
produces_for: [github-flow]
outputs: ["ProjectName/RELEASING.md", "ProjectName/CHANGELOG.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Release Engineering

Apply when: OSS project needs its **first public release** (`v0.1.0` / `v1.0.0`), or to **formalize the release process** for an existing package. Covers: versioning, changelog, tags, pre-releases, deprecation, hotfix-flow.

Run **after `oss-development`** (needs base repo structure + `Architecture.md`). Often paired with `dependency-audit` (SBOM before publish) and `dx-design` (what break/feature/fix means to the user).

## Do NOT apply when
- Internal/private project, no public consumers — version by date, not SemVer; use simplified process.
- Stable release-flow already exists and need not change — decline.
- First `v0.1.0` not out and no README yet — do `oss-development` + `dx-design` first.

## 5 decisions

### 1. SemVer contract
Fix **what counts as** `MAJOR`/`MINOR`/`PATCH` for this package:

| Change | Class | Example |
|:---|:---|:---|
| Remove public API / breaking signature | MAJOR | removed `Brain.query()`, renamed param |
| Change behavior of existing API (contract kept) | MINOR or MAJOR? | **Depends on package. Fix it** |
| New public API, non-breaking | MINOR | added `Brain.stream()` |
| Bug-fix, no public API change | PATCH | fixed race in `Brain.query()` |
| Private code / refactor | PATCH or skip | internal edits |
| Experimental API behind flag | MINOR | `experimental.stream()` |
| Deps: minor/patch update | PATCH | bumped lodash |
| Deps: major update of transitive dep | MINOR or MAJOR | **if in peerDeps → MAJOR** |

Also fix in RELEASING.md:
- What counts as public API (everything exported? only `index.ts`?)
- Is `engines.node` change MAJOR (recommend: yes)
- Are TS-type changes MAJOR (used as library → yes; internal → no)
- Platform requirements (min PHP/Node/Dart) — MAJOR on change

### 2. Pre-release strategy
Before `1.0.0` — `0.x.y`. After `1.0.0`:
- `1.2.0-alpha.1` — internal experiments, **no** stability promise
- `1.2.0-beta.1` — public tests, API frozen, catching bugs
- `1.2.0-rc.1` — candidate, blocker-fixes only
- `1.2.0` — stable

**npm/Packagist/pub.dev tag rule**: alpha/beta/rc publish under dist-tag `next` or `beta`, **not `latest`**.

### 3. CHANGELOG.md format
**Keep a Changelog** (https://keepachangelog.com):

```markdown
## [Unreleased]
### Added
- ...
### Changed
- ...
### Deprecated
- ...
### Removed
- ...
### Fixed
- ...
### Security
- ...

## [1.2.0] - 2026-01-15
### Added
- `Brain.stream()` — agent response streaming ([#42](link))
### Fixed
- Race in `Brain.query()` on concurrent calls ([#51](link))

## [1.1.0] - 2025-12-10
```

Filled-in CHANGELOG example (Keep a Changelog) with section breakdown — [references/changelog-example.md](references/changelog-example.md).

### Agent adds itself
- **Min Node/PHP/Dart version.** Set in `engines.node` / `composer.json:require.php` / `pubspec.yaml:environment.sdk`. No "works on any version".
- **`provenance` / signed releases.** Mandatory for `v1.0+`. Explain OIDC setup.
- **`peerDependencies` traps.** If package is a plugin (for a framework), `peerDeps` defines MAJOR coupling.
- **Hotfix-flow.** What to do on critical bug in `1.2.3` when main is on `1.3-alpha`: branch `release/1.2.x`, fix, `1.2.4`, backport to main.
- **LTS strategy (if applicable).** Library-level (BrainKit) — no. Core infrastructure — yes, 1 LTS parallel to current.

## Everyday CHANGELOG discipline

This skill triggers on release tasks — but the CHANGELOG rots between releases, not during
them: a missed `[Unreleased]` line accumulates silently and near-impossible to reconstruct as
a batch later. So discipline is not "once per release" but **every content commit**. Two
tracks, by repo type:

**Packages (SemVer-strict).** Type from the conventional commit: `fix:` → PATCH, `feat:` →
MINOR. **MAJOR is ALWAYS the owner's manual decision** — breaking-change is NEVER inferred
automatically; only a human decides a change breaks the contract enough for a major. Every
content commit leaves a line in `[Unreleased]` under the right Keep a Changelog group
(Added/Changed/Deprecated/Removed/Fixed/Security).

**Projects (no SemVer).** Compact entry + commit link, GitHub-style:
`- short description ([abc1234](…/commit/abc1234))`. Systematize into a coherent description
in a batch, at release time, not line-by-line.

Entry format in both tracks — Keep a Changelog (above); write entries in Russian
(`writing-style`).

**Enforcement.** Discipline doesn't rely on session memory: the `harness` commit gate
(`--changelog-gate`, `changelog-gate` hook on `git commit`) warns into the model's context
when the diff is content-bearing and the CHANGELOG is untouched — **warning, not a block**.
A conscious exception (docs/chore commit) is auto-skipped by commit type; anything else needs
an explicit `[changelog-skip]` marker in the commit message. Version-assembly mechanization
from `[Unreleased]` + conventional commits — `skiller release` (optional, umbrella tag
`vX.Y.Z`).

## Output files

### `ProjectName/RELEASING.md`

```markdown
---
project: [ProjectName]
type: oss-process
based_on: oss-development.md
---

# Releasing [ProjectName]

## SemVer contract
[MAJOR/MINOR/PATCH table for this package]

## Public API
- Public: [...]
- Private: [...]
- Platform requirements (min runtime): [...]

## Pre-release
- alpha / beta / rc → dist-tag
- When to publish to `latest`

## CHANGELOG
- Standard: Keep a Changelog
- Automation: [changesets / release-please / manual]

## Release pipeline
- Trigger: push tag `v*`
- Steps: test → build → publish → sign → changelog
- Secrets: [...]
- 2FA: [...]

## Deprecation policy
- Mark → Wait (≥ 1 MAJOR) → Remove

## Hotfix flow
- branch `release/X.Y.x` → fix → tag `X.Y.Z+1` → backport to main

## New-release checklist
- [ ] All tests green on all platforms
- [ ] CHANGELOG [Unreleased] → new version
- [ ] Version bumped in `package.json`/`composer.json`/`pubspec.yaml`
- [ ] Tag `vX.Y.Z` created
- [ ] GitHub Release with release notes published
- [ ] Artifact published to registry with provenance
- [ ] Twitter / Discord / mailing list notified (for MAJOR)
```

### `ProjectName/CHANGELOG.md`
Keep a Changelog format above. Created empty with `[Unreleased]` section if missing.

## Links
- `oss-dev/oss-development` — prerequisite: base repo structure + `Architecture.md`.
- `oss-dev/github-flow` — consumer: Issue→PR→Tag→Release chain executes this SemVer contract + CHANGELOG format.
- `oss-dev/dependency-audit` — SBOM as release artifact; vuln-fixes affect version.
- `oss-dev/dx-design` — what break/feature/fix means to user; TS-types as public API.
- `general/git-commit-rules` — commit format the SemVer oracle uses to determine bump.
- `devops/ci-cd` — GitHub Actions release-pipeline (tag → test → build → publish).
- `php-packages/laravel-package-release` — release notes/tags/validation for PHP packages (language-specific).

## Hard prohibitions
NEVER:
- Publish `1.0.0` without RELEASING.md and CHANGELOG.md
- Do MAJOR without migration guide in release notes
- Remove public API in MINOR (even "nobody uses it" — no)
- Force-push a tag (release tags immutable)
- Publish without 2FA / provenance for `v1.0+`
- Use "git log dump" instead of changelog
- Close a content commit (feat/fix/perf/refactor) without a line in `[Unreleased]` — except
  docs/chore/test or an explicit skip marker

<!-- ru-source-sha256: 8175789d42bf4ff125fa348794731d85a5fb9e091781a7e761a79bb29591a20c -->

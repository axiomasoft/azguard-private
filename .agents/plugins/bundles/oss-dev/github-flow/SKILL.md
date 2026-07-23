---
name: github-flow
bucket: oss-dev
version: 0.2.0
description: "Chain Issue → Branch → PR → Merge → Tag → Release on GitHub: naming, labels, SemVer oracle by commit types, Issue/PR templates. Commit format — git-commit-rules; changelog & pipeline — release-engineering; PR review — gh-review."
risk: write
persona: oss-dev
tags: [oss, github, git, semver, release]
requires: [git-commit-rules, release-engineering, gh-review]
produces_for: []
outputs: [".github/PULL_REQUEST_TEMPLATE.md", ".github/ISSUE_TEMPLATE/"]
snippets: [pull-request-template.md, issue-templates.md]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: GitHub Flow

Apply when: GitHub OSS repo changes run **from Issue to release** and the agent participates in the chain (creates Issue/PR via `gh`, names branches, assigns labels, computes next version from commits, creates tag + GitHub Release).

Boundary with neighbors:
- commit message format/style (types, scopes, BREAKING CHANGE) — `general/git-commit-rules`;
- package SemVer contract, CHANGELOG format, release pipeline, pre-releases, deprecation — `oss-dev/release-engineering`;
- this skill — the process chain between them (Issue→branch→PR→tag→release).

## Do NOT apply when
- Repo not on GitHub (GitLab, internal git without Issues) — use only `git-commit-rules`.
- Need to formalize the release process itself (what counts as MAJOR, deprecation policy) — `release-engineering`.
- One-off fix in someone's fork without Issues/labels rights — plain upstream PR, not this flow.

## Lifecycle

```
Issue → Branch → Commits → PR → Review → Merge → SemVer Oracle → Tag → GitHub Release
  ▲                          ▲                        ▲             ▲
  agent questions            agent questions          version       only after
  (type/priority)            (issues/changelog)       confirmation   merge
```

Checkpoints where agent **MUST stop and ask**:
1. Before creating Issue — type, priority, component, reproduction version.
2. Before creating PR — related Issues, change type, whether CHANGELOG entry needed.
3. Before tag — confirm version proposed by SemVer oracle.

## Issues

Mandatory questions before creating:
1. **Type** — bug | feature | enhancement | security | docs | refactor | chore
2. **Priority** — critical | high | normal | low
3. **Affected component** (from project structure map)
4. **Package version** where issue reproduces (for bug/security)

Title:
```
[type] Short imperative description (≤72 chars)
```
Examples: `[bug] Fix config cache invalidation on publish`, `[feature] Add scoped token resolution`.

Labels by type:

| Issue type | Labels |
|:---|:---|
| bug | `bug`, `needs-triage` |
| feature / enhancement | `enhancement` |
| security | `security`, `priority:critical` |
| docs | `documentation` |
| refactor | `refactor`, `tech-debt` |
| chore | `chore` |

Meta-labels `semver:patch` / `semver:minor` / `semver:major` set by impact assessment (see SemVer Oracle); `semver:major` only after user confirmation.

## Branch Naming

Format: `<type>/<issue-number>-<slug>`
- slug: lowercase letters, digits, hyphens; ≤50 chars after `<type>/`.
- Branch types: `feat/`, `fix/`, `hotfix/`, `docs/`, `refactor/`, `chore/`, `security/`.
- Create branch after Issue confirmed; take title from `gh issue view <N>`.

```
feat/42-scoped-token-resolution
fix/17-config-cache-invalidation
security/38-path-traversal-loader
```

## Pull Requests

Before creating, agent asks or computes:
1. Related Issues (`Closes #N` / `Refs #N`)
2. Change type — bugfix | feature | breaking | security | docs
3. Affected components (from diff)
4. Whether CHANGELOG entry needed (section rules — `release-engineering`)

Title:
```
<type>(<scope>): <description> (#<issue>)
```
Example: `feat(provider): scoped token resolution (#42)`. Type/scope format — per `git-commit-rules`.

Body & labels:
- Body — per `snippets/pull-request-template.md` (copied to `.github/PULL_REQUEST_TEMPLATE.md`). Labels mirror Issue, plus:
- `ready-for-review` — when PR leaves draft;
- `needs-changelog` — CHANGELOG not updated;
- `breaking-change` — has `BREAKING CHANGE` footer or `!` in type.

Review: do it via `gh`, not by loading whole files: `gh pr diff <N>`, `gh pr view <N> --comments`, `gh pr review <N> --approve|--request-changes -b "..."`. Full algorithm & context economy — skill **`oss-dev/gh-review`**; finding criteria & severity — `quality/code-review`.

## SemVer Oracle — next version from commits

Analyze commits between last tag and HEAD: `git log $(git describe --tags --abbrev=0)..HEAD --pretty=format:"%s" --no-merges`.

| Found in commits | Bump |
|:---|:---|
| `BREAKING CHANGE` footer or `!` after type (`feat!:`) | MAJOR |
| `feat` (no breaking) | MINOR |
| `fix`, `perf`, `security` | PATCH |
| only `docs`, `style`, `refactor`, `test`, `build`, `ci`, `chore` | none (no release) |

Take max bump across all commits. What counts as breaking **for this package** — the SemVer contract in `release-engineering` (RELEASING.md); on conflict, contract beats commit type.

Confirmation dialog:
```
Current version: 1.4.2 (last tag)
Commits since tag: feat(2), fix(1), docs(3)
Proposed version: 1.5.0
Confirm / specify manually / cancel?
```

Tag format: `vX.Y.Z` stable; `vX.Y.Z-alpha.N` / `-beta.N` / `-rc.N` pre-releases (pre-release policy & dist-tags — `release-engineering`).

## Merge → Tag → Release

Tag created **only after**: user version confirmation, CHANGELOG updated (new version section with date), PR merged to default branch.

```
1. git checkout main && git pull
2. [Unreleased] section → [X.Y.Z] - YYYY-MM-DD in CHANGELOG.md
3. bump version in package manifest (if version field used)
4. git commit -m "chore(release): ..." (style — git-commit-rules)
5. git tag vX.Y.Z -m "Release vX.Y.Z" && git push origin main --tags
6. gh release create vX.Y.Z --notes-file <CHANGELOG-section>
7. publish to registry — via CI on tag, not by hand
```

Ready workflow — `devops/ci-cd/snippets/github-actions-release.yml`; pipeline rules (tags-only, provenance, 2FA) — `release-engineering`.

## Agent adds itself
- **`.github/PULL_REQUEST_TEMPLATE.md` and `ISSUE_TEMPLATE/`** from snippets if absent — propose adding on first PR/Issue.
- **Issue ↔ PR link.** `Closes #N` in commit footer or PR body — Issue auto-closes on merge.
- **Branch cleanup.** After merge propose deleting branch (`gh pr merge --delete-branch`).
- **Release language specifics.** For PHP packages — pass gate from `references/php-package-gate.md` before tag.

## Links
- [references/php-package-gate.md](references/php-package-gate.md) — composer.json gate, keywords, Packagist deploy
- [snippets/](snippets/) — PR & Issue templates
- `../release-engineering/SKILL.md` — SemVer contract, CHANGELOG, pipeline, deprecation
- `../gh-review/SKILL.md` — PR review/handoff via gh with context economy
- `../../general/git-commit-rules/SKILL.md` — commit format, scopes, BREAKING CHANGE
- `../../devops/ci-cd/snippets/github-actions-release.yml` — release workflow on tag
- `devops/gitops` — team branch model (main/develop/feature/hotfix) & branch protection; github-flow — GitHub projection of it (Issue→PR→Release).

## Hard prohibitions

NEVER:
- Create Issue/PR without answers to mandatory questions (type, priority, related Issues).
- Push tag before PR merged to default branch.
- Assign `semver:major` / do MAJOR bump without explicit user confirmation.
- Bump package version inside a feature/fix PR — only in the release commit.
- Duplicate the package SemVer contract — it lives in RELEASING.md (`release-engineering`).
- Force-push to a branch with open review without warning reviewers.

<!-- ru-source-sha256: 97225a443af13ff09dbd40ddabdfaab56ea62774a8b5ae15705066405b7d0731 -->

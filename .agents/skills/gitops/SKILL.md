---
name: gitops
bucket: devops
version: 0.3.0
description: "Team Git strategy: branch model (main/develop/feature/hotfix), PR workflow, branch protection"
risk: read
persona: operator
tags: ["git", "gitops", "github"]
requires: []
produces_for: ["github-flow"]
outputs: []
snippets: ["branch-strategy.md", "commit-conventions.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

Scope: gitops = *team strategy* (which branches exist, how code reaches `main`, who/how merges). Commit-message format + local pre-commit order → skill `general/git-commit-rules` (husky/commitlint, safe `git add`/`git commit` order).

## Branch model

| Branch | Purpose | From | Merges into |
|:---|:---|:---|:---|
| `main` | production, always deployable | — | — |
| `develop` | integration (if gitflow-lite used) | `main` | `main` via release-PR |
| `feat/*` | new functionality | `develop` (or `main` for trunk-based) | `develop`/`main` via PR |
| `fix/*` | bug fix | `develop`/`main` | via PR |
| `chore/*` | maintenance (CI, deps, configs) | `develop`/`main` | via PR |
| `hotfix/*` | urgent production fix | `main` | `main` + back-merge into `develop` |

- Naming: `feat/<ticket>-short-description`, kebab-case, no personal prefixes.

## PR workflow

1. Branch from up-to-date `main`/`develop` (`git fetch` + `git switch -c feat/... origin/develop`).
2. Small atomic commits (format → `general/git-commit-rules`).
3. PR: conventional-commit-style title; description "what/why/how to verify"; link to ticket.
4. Green CI + ≥1 approval → merge. Merge strategy: squash for feature branches (one logical unit in history), merge-commit for release/hotfix.
5. Delete branch after merge.

## Branch protection

- `main` (and `develop`): forbid direct pushes, require PR, require green CI, min 1 review, forbid force-push.
- Hotfix does NOT bypass protection — same PR process, expedited review.

## Snippets

- `branch-strategy.md` → set up branch model for new repo, "where to branch from" debate.
- `commit-conventions.md` → conventional-commit-types cheatsheet (details → `general/git-commit-rules`).

## Checklist

- [ ] Branch named per convention (`feat/*`, `fix/*`, `chore/*`, `hotfix/*`)
- [ ] Branch created from up-to-date base branch
- [ ] Changes reach protected branches only via PR
- [ ] CI green, approval present, correct merge strategy chosen
- [ ] Hotfix merged into both `main` and back into `develop` (if develop used)
- [ ] Branch deleted after merge

## Links

- Commit-message format + pre-commit order: skill `general/git-commit-rules`
- Related: `oss-dev/github-flow` (full Issue→Branch→PR→Merge→Tag→Release chain over this branch model), `oss-dev/gh-review` (PR review via gh CLI), `oss-dev/release-engineering` (release pipeline + SemVer), `devops/ci-cd` (green CI as merge gate for protected branches)

<!-- ru-source-sha256: 7dba8d0cde349e12a3353e6ddf3b1f71fa8da27f0394c6bcdb3eadd711f3d722 -->

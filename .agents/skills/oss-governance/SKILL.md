---
name: oss-governance
bucket: oss-dev
version: 0.1.0
description: LICENSE, CoC, CONTRIBUTING, MAINTAINERS, RFC process, security policy for an OSS project
risk: draft
persona: oss-dev
tags: [oss, compliance, license, security, documentation]
requires: [oss-development]
produces_for: [dependency-audit]
outputs: ["ProjectName/LICENSE", "ProjectName/CODE_OF_CONDUCT.md", "ProjectName/CONTRIBUTING.md", "ProjectName/MAINTAINERS.md", "ProjectName/SECURITY.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: OSS Governance

Apply when: OSS project published for the first time **or** starts accepting external contributions. Must run **after `oss-development`** (base structure exists). Pairs with `dependency-audit` (compliance) and `dx-design` (issue/PR templates).

## Do NOT apply when
- Personal pet-project not accepting PRs — LICENSE + minimal README is enough.
- Internal company package — governance replaced by internal code-review process.
- Mature governance files already exist — refuse, don't rewrite.

## 6 governance artifacts (by priority)
LICENSE / CoC / CONTRIBUTING / SECURITY / MAINTAINERS / RFC — see [references/governance-artifacts.md](references/governance-artifacts.md).

### CONTRIBUTING.md
```
## Quick start (dev)
[install + run tests in 3 commands]

## Code style
- Linter: ...
- Format: ...
- Pre-commit: ...

## Commits
- Conventional Commits (feat:, fix:, ...)
- Reference issue: `fix: handle X (#42)`

## Branch / PR flow
- Fork → branch → PR → review → squash merge

## Tests
- Unit, integration, e2e
- Coverage threshold: X%
- What must be covered

## Documentation
- Any public API change → update docs/ + CHANGELOG
- Screenshots for UI changes

## What WE ACCEPT
- bug fixes
- documentation
- new features AFTER agreement in issue / RFC

## What WE DON'T ACCEPT
- Refactor without justification
- Breaking changes without RFC
- Code style PR (we automate this)
```
**Goal:** contributor understands "how to help" in 2 minutes of reading.

### MAINTAINERS.md
```markdown
# Maintainers

## Core
- @username (GitHub) — TZ, responsibilities, contact preference

## Active reviewers
- @username — areas: docs, ci

## Decision process
- Bug fixes / docs: 1 maintainer approval
- New features: 2 approvals + RFC if major
- Breaking changes: lazy consensus 7 days
- Security: maintainer + 1 reviewer, expedited

## Becoming a maintainer
[criteria: X merged PRs, Y months of activity, etc.]
```

### SECURITY.md
**GitHub standard.**
```markdown
# Security Policy

## Supported versions
| Version | Supported |
|:---|:---|
| 2.x | ✅ |
| 1.x | ✅ (security fixes only, until YYYY-MM) |
| < 1.0 | ❌ |

## Reporting a vulnerability
- **Do not open a public issue.**
- Email: security@[domain] (or privately disclosed GitHub Security Advisory)
- PGP key: [link]
- Expected response: 48 hours
- Disclosure timeline: 90 days by default (negotiable)

## Scope
- In scope: [...]
- Not in scope: [...]
```

### RFC process (projects with active community)
Apply when: feature affects > 1 public API surface, OR changes dx-contract, OR break-change.
```
docs/rfcs/
├── 0000-template.md
├── 0001-streaming-api.md
└── 0002-deprecation-of-foo.md
```
**RFC template:** Summary → Motivation → Detailed design → Drawbacks → Alternatives → Open questions → Migration path.

**Process:**
1. PR with RFC into `docs/rfcs/` (status `draft`)
2. Discussion ≥ 14 days
3. Lazy consensus or vote from maintainers
4. Accepted → status `accepted` + issue for implementation
5. Implemented → status `implemented` + link to release

Small-team: simplifies to "RFC = GitHub Discussion with a template".

## Agent adds on its own
- **DCO vs CLA.** Most OSS projects: **DCO suffices** (Developer Certificate of Origin — `git commit -s`). CLA only if potential commercialization / re-licensing planned.
- **Bus factor.** In `MAINTAINERS.md` — what to do if an active maintainer disappears (repo inheritance, fallback contact).
- **Funding.** `.github/FUNDING.yml` if applicable (GitHub Sponsors, OpenCollective). Not for every project.
- **Issue / PR templates.** `.github/ISSUE_TEMPLATE/*.yml`, `.github/PULL_REQUEST_TEMPLATE.md`. Partially overlaps `dx-design`.
- **Branch protection** (admin task): require review, require CI, forbid force-push to main and release/.

## Output file structure
- `ProjectName/LICENSE` — full text of chosen license from https://choosealicense.com. Do NOT edit the text.
- `ProjectName/CODE_OF_CONDUCT.md` — Contributor Covenant 2.1 with reports email substituted.
- `ProjectName/CONTRIBUTING.md` — structure above.
- `ProjectName/MAINTAINERS.md` — structure above with real people.
- `ProjectName/SECURITY.md` — structure above.
- (optional) `ProjectName/docs/rfcs/0000-template.md` — RFC template if process enabled.

## Links
- `oss-dev/oss-development` — prerequisite: base repo structure.
- `oss-dev/dependency-audit` — consumer: project LICENSE/SPDX sets license-compatibility criterion for dependencies.
- `oss-dev/dx-design` — overlap: issue/PR templates and CONTRIBUTING are part of both DX and governance.
- `oss-dev/github-flow` — where ISSUE_TEMPLATE/PR templates go and how PR-flow runs.
- `architect/legal-compliance` — regulation (GDPR/CCPA/AI Act/DMCA) for product; here — only OSS licensing/CoC.

## Hard prohibitions
NEVER:
- Publish without LICENSE (legally a repo without license = "all rights reserved", nobody may use it).
- Invent your own CoC — Contributor Covenant covers 99% of cases.
- Merge LICENSE.md into README (separate files by convention).
- Use AGPL/BUSL without explicit business justification (sharp adoption loss).
- Omit SPDX in `package.json`/`composer.json` (hurts consumers' dependency-audit).
- Accept PR without CONTRIBUTING.md (no rules → every PR review negotiated from scratch).
- Ignore SECURITY.md (without it vulnerabilities arrive in public issues).

<!-- ru-source-sha256: 03f32cb4066de3775af032de292ae6635c04c887eb7c377517130cfce2635bf0 -->

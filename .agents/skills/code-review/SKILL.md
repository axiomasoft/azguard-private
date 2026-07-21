---
name: code-review
bucket: quality
version: 0.1.0
description: Code review process & checklist: mandatory checks, comment severity grades, async vs sync, PR-comment template
risk: draft
persona: quality
tags: [quality, validation, review, github, workflow]
requires: []
produces_for: []
outputs: ["docs/03_Dev/Code_Review_Guide.md"]
sha256: ""
snippets: ["code-review.md"]
adapters: [claude, cursor, fable]
---

# Skill: Code Review Guide

Apply when: team > 1, git workflow with PRs. A process — codify once, revisit quarterly. Trigger at team formation or after a run of bad merges.

## When NOT to apply
- Solo dev with no hiring plans (prefer pair session); prototype stage without publication.
- Hotfix for critical prod bug → merge fast + post-merge review.
- Auto-generated code (migrations, OpenAPI client) → review only the schema diff, not the code.

## Step 1. Mandatory checks (priority order)

| Priority | Category | Examples |
|:---|:---|:---|
| **P0** | Security | SQL injection, hardcoded secrets, broken auth, missing input validation |
| **P0** | Correctness | logic fails on edge-case, race condition, off-by-one, incorrect SQL |
| **P1** | Tests | no coverage for new logic, tests deleted without explanation |
| **P1** | Performance | N+1 queries, missing index, synchronous IO in hot path |
| **P2** | Architecture | layer violation, circular dependencies, domain leak into adapter |
| **P2** | Readability | magic numbers, bad names, function > 50 lines, deep nesting |
| **P3** | Style | formatting, naming convention — linter's job, not by eye |

P3 = linter's job, not reviewer's. Frequent P3 comments → pre-commit hook not configured.

## Step 2. Comment severity grades

Use prefixes (critical for AI agents and author speed). No prefix → treated as `[issue]`.

| Prefix | Meaning | Author action |
|:---|:---|:---|
| **`[blocker]`** | don't merge until fixed | must fix or justify refusal |
| **`[issue]`** | important, can be separate PR | fix now or create ticket |
| **`[nit]`** | minor / style | author's discretion, can ignore |
| **`[question]`** | didn't get it, explain | answer; add code comment if needed |
| **`[praise]`** | good solution | nothing; motivation booster |
| **`[suggestion]`** | alternative to consider | discretion |

## Step 3. Async vs Sync

| Mode | When | Pros | Cons |
|:---|:---|:---|:---|
| **Async (PR comments)** | default | non-distracting, time to think | slower, ping-pong trap |
| **Sync (call / pair review)** | complex PR > 500 lines, junior author, architectural change | fast, teaching | costs both |
| **Pre-PR sync** | large feature, contentious approach | catch problems before coding | needs discipline |

2-round rule: dispute unresolved after 2 async rounds → switch to sync. Beyond that → escalate to tech lead.

## Step 4. Review SLA

| PR size | Target SLA | Hard cap |
|:---|:---|:---|
| < 50 lines | 2 hours | 1 working day |
| 50–200 | 4 hours | 1 working day |
| 200–500 | 1 day | 2 days |
| > 500 | **split the PR** | — |

Anti-pattern: PR > 500 lines. Reviewer loses attention after ~400, rest unread = rubber stamp. Require splitting.

## Step 5. Author responsibilities (before Request Review)
- [ ] PR description: what / why / how tested
- [ ] Screenshots for UI changes
- [ ] Self-review via diff on GitHub (fresh eyes)
- [ ] CI green (lint + tests)
- [ ] Tests for new logic added
- [ ] Migration / breaking change flagged in description
- [ ] PR < 500 lines (or explanation why it can't be split)

If any missing → reviewer may close with "complete it and re-open".

## Step 6. Reviewer responsibilities
- Don't block on `[nit]` — only `[blocker]` blocks.
- Approve = "I'm ready to own this code together with the author".
- Don't know the area (DB migration, ML, security) → call an expert, don't approve blindly.
- `LGTM` without reading the code is forbidden. Min: looked at diff, read tests, mentally checked one scenario.
- On junior PRs → teaching comments with doc links, not "just fix it".

## What the agent adds
- PR description template (what / why / how tested / breaking changes / screenshots).
- Comment template with example phrasings for common situations.
- Review metrics: avg time to first review, avg rounds per PR, % PR > 500 lines — for revising the process.
- Escalation for merge without approve (tech lead only, reason recorded).

## Output file structure `Code_Review_Guide.md`

```markdown
# Code Review Guide: [ProjectName / Team]

## 1. Что обязательно проверять (P0-P3 матрица)
## 2. Severity префиксы ([blocker]/[issue]/[nit]/[question]/[praise]/[suggestion])
## 3. Async vs Sync — правила выбора режима
## 4. SLA на review (по размеру PR)
## 5. Author checklist (до Request Review)
## 6. Reviewer checklist (минимум для approve)
## 7. Эскалация спорных решений
## 8. Метрики и пересмотр процесса (раз в квартал)
```

## Hard prohibitions — DO NOT
- Approve without reading the code (rubber stamp).
- Block merge on `[nit]` / style / formatting.
- Self-approve (even if sole senior — call a second person).
- Ignore `[blocker]` without written justification.
- Merge a PR with red CI (even "we'll fix it later").
- PR > 500 lines without explicit tech-lead permission.
- Skim-review PRs with security/payments/auth changes.

## Links
- `oss-dev/gh-review` — review mechanics via `gh` CLI (diff/comment/review, context-saving); this skill = process & checklist, gh-review = execution tool.
- `quality/code-simplifier` — what to suggest the author for simplifying the fresh diff (input for `[suggestion]`/`[nit]`).
- `quality/test-strategy` — the "tests for new logic?" criterion (P1) relies on coverage strategy.
- `quality/mutation-testing` — objective test-quality gate instead of manual "tests exist".
- `general/git-commit-rules` — commit/PR conventions checked during review.

<!-- ru-source-sha256: 2998019f26efd7f64af7d7869ead6d92129f08259a0f0a66277beadf05585efe -->

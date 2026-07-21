---
name: refactoring-plan
bucket: quality
version: 0.1.0
description: Safe refactoring plan: scope, Mikado / strangler pattern, atomic steps, done criteria, rollback
risk: draft
persona: quality
tags: [quality, refactor, refactoring, validation, workflow]
requires: [tech-debt-audit]
produces_for: [testing-safety-report]
outputs: ["docs/03_Dev/Refactoring_Plan.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Refactoring Plan

Use when: `tech-debt-audit` picked a concrete architectural or large code-level debt to fix (`architectural`, or big `code` with status 🔴/🟡 in impact × effort matrix).

## Do NOT use when
- Small cleanup < 1 day → just do it in the current PR
- Rewrite to new language/framework (replatform/rewrite — different methodology)
- No `tech-debt-audit` context (risk refactoring the wrong thing)
- Code freeze (near major release, before demo) → defer

## Principle
- Safe refactoring = **system behavior unchanged**, only internal structure changes.
- Core rule: **code always green between steps**. No "week in a broken branch". If a step breaks the system for more than a day → step is **too big**, split it.

## Step 1. Scope — in / NOT in

| | Included | Excluded |
|:---|:---|:---|
| Modules | specific paths | everything else |
| Behavior | unchanged | API/UX changes — separate PR |
| Dependencies | can update if required | major upgrade — separate plan |
| Tests | add missing | change existing behavior |
| Documentation | updated in parallel | does not block merge |

- Scope fixed in writing before start. Any expansion → revise the plan, not "on the fly".

## Step 2. Strategy — 3 patterns

| Pattern | When | How |
|:---|:---|:---|
| **Mikado Method** | Tangled dependency, unclear where to start | Make desired change → breaks → revert → record "what blocks it" → do that first. Recursive. Yields prerequisite graph (Mikado tree) |
| **Strangler Fig** | Replace legacy module with new one, system can't stop | New impl alongside old, gradually redirect traffic/calls. Delete old when nothing calls it |
| **Branch by Abstraction** | Replace inside one class/module without stopping | Introduce interface over old impl → write new → switch via feature flag → delete old |

- **Forbidden pattern:** "long-lived refactor branch" — branch > 2 weeks detached from main. Conflicts eat all the savings.

## Step 3. Atomic steps — format
Each step = one PR. Each PR must:
- Be **green** (CI green)
- Preserve behavior (existing tests pass)
- Be **reversible** (one-button revert)
- Have **independent value** (even if next step is cancelled, this merge does no harm)

Step format:
```markdown
### Step N: [short name]

- **Step goal:** what gets better after merge (one line)
- **Changes:** list of files / modules
- **Tests:** what is added / changed
- **Behavioral check:** concrete scenario that must work identically BEFORE and AFTER
- **Rollback:** revert PR / feature flag off / nothing (if step is purely additive)
- **Effort:** hours
- **Depends on:** Step N-1 (or —)
```
- Average: 4–12 steps per plan. Fewer than 3 → not a plan, just a PR. More than 15 → scope too big, split into 2 plans.

## Step 4. Definition of Done

| Criterion | Metric | How to check |
|:---|:---|:---|
| Behavior preserved | E2E happy paths green | smoke run on staging |
| Tests in place | coverage not dropped | coverage diff in CI |
| Old code deleted | grep on old name = 0 | manual grep + linter rule |
| Documentation updated | Architecture.md / ADR | review |
| Performance not degraded | p95 latency delta < 5% | benchmark before/after |
| Dependencies updated | dep tree cleaned | `dependency-audit` |
| Feature flags removed | if branch-by-abstraction used | grep |

- Old code **must be deleted** within the plan. "Leave it a couple sprints just in case" = new tech debt.

## Step 5. Rollback strategy

| What happened | Action |
|:---|:---|
| One step broke staging | revert that step's PR, continue with next |
| Series of steps caused cascading prod bugs | freeze, revert last N steps to green state, post-mortem |
| Whole refactor was a mistake (new approach worse than old) | keep Branch-by-Abstraction split, delete new impl, keep interface (useful) |

- **Forbidden:** rolling back the whole refactor via reverting 10 PRs in a row — too fragile. If you need that, the steps weren't atomic.

## Step 6. Monitoring metrics during
```
- CI build time — must not grow (refactoring should simplify)
- Test count / coverage — grows or stable
- Bug rate in affected modules — does not grow after each step
- PR review time — does not grow (if it grows → steps too big)
```
- Check weekly. If degradation 2 weeks in a row → pause, investigate.

## Agent adds itself
- Link to `tech-debt-audit` entries: which TD-NNN this plan closes
- `[refactor]` PR description template with mandatory sections (scope / strategy / rollback / DoD checklist)
- Warning if plan > 15 steps or > 8 weeks — high abandoned risk
- Recommend feature flag tool for the stack if Branch by Abstraction chosen
- Backlinks in `Refactoring_Plan.md` → `Tech_Debt_Register.md`

## Links
- `quality/tech-debt-audit` — scope source: which TD-NNN the plan closes (requires)
- `quality/testing-safety-report` — post-hoc summary after executing refactor steps
- `quality/code-simplifier` — small cleanup of fresh diff (what does NOT need a plan)
- `quality/test-strategy` — Definition of Done relies on coverage policy and happy-path E2E
- `system/cross-project-coordinator` — when refactor touches duplicates across multiple projects
- `php/php-upgrade-checklist` — major framework upgrade goes as a separate plan, not "along the way"

## Output file structure `Refactoring_Plan.md`
```markdown
# Refactoring Plan: [short name]

## 1. Context
- Related TD from `Tech_Debt_Register.md`: TD-007, TD-012
- Business rationale (one line)

## 2. Scope
- ✅ Included
- ❌ Excluded

## 3. Strategy
- Chosen pattern (Mikado / Strangler / Branch-by-Abstraction)
- Choice rationale

## 4. Atomic steps (4–12)
[Each step per format from Step 3]

## 5. Definition of Done (checklist)

## 6. Rollback plan

## 7. Monitoring metrics

## 8. Owner / Reviewers / Timeline
- Owner:
- Reviewers:
- Estimate: N weeks
- Not later than: YYYY-MM-DD
```

## Hard prohibitions
MUST NOT:
- Change system behavior within a refactoring PR (separate PR/plan)
- Long-lived refactor branch > 2 weeks without merge into main
- Steps breaking CI for more than one day
- Leave "old code for a couple sprints" — that's new tech debt
- Refactoring without a rollback plan for each step
- Start without scope fixed in writing
- Plans > 15 steps or > 8 weeks without splitting
- "Along the way" bump a major framework version — separate plan

<!-- ru-source-sha256: 7c4b2f0f9463f55b6b73fa6f3a11c0497ac41ca9542c46f6aee0ab937f612e73 -->

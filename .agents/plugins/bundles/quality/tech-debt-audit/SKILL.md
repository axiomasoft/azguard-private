---
name: tech-debt-audit
bucket: quality
version: 0.1.0
description: Tech-debt inventory, classification (architectural/code/dependency/test/process), prioritization by impact × effort, payback ROI calc
risk: draft
persona: quality
tags: [quality, validation, refactor, dependencies, workflow]
requires: []
produces_for: [refactoring-plan]
outputs: ["docs/03_Dev/Tech_Debt_Register.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Tech Debt Audit

Apply when: team says "everything is slow", feature delivery velocity drops, new bugs recur in old places, junior onboarding > 2 weeks. Also: quarterly, even if "all good". On tech-lead change — mandatory.

## When NOT to apply
- Pre-MVP stage (too early to classify debt; "all code is debt")
- One-off scripts, migrations
- Auto-generated code (regenerate, don't audit)
- < 3 months since last audit — premature

## Principle
Tech debt = not a bug, not bad code — a (conscious or unconscious) trade-off between speed-now and cost-of-change-later. Goal: not fix everything, but make debt **visible** + **manageable**. 70% of debt should stay unfixed — fine if risk/cost is understood.

## Step 1 — Classification (5 types)
Each register entry gets exactly **one** primary type (optional secondary).

| Type | What | Symptom | Who sees |
|:---|:---|:---|:---|
| **Architectural** | Wrong module boundaries, cyclic deps, monolith-vs-services (or reverse) | One feature change touches 5+ files | Architect, tech lead |
| **Code-level** | Long functions, copy-paste, magic, dead code | Junior can't grasp in 30 min | All devs |
| **Dependency** | Outdated libs, known CVE, deprecated API, abandoned packages | `dependency-audit` fires | DevOps, security |
| **Test** | No tests / flaky / slow / coverage gaps in critical spots | CI red/unstable, fear of refactor | All |
| **Process** | No CI, manual deploys, no review, hardcoded configs, secrets in repo | Time-to-prod > 1 day, frequent rollbacks | Tech lead, ops |

## Agentic technical debt (AI-era special case)
Compounding subtype of `architectural` debt, specific to AI-built codebases. Normal debt is linear/slow; **agentic debt compounds**: without specs + architectural constraints written where AI can read, every session re-derives fundamental decisions and they drift → codebase with no coherent mental model. Surfaces late (at scale), forces rewrite-from-scratch.

**Antidote (record as process/architectural debt if absent):**
- **CLAUDE.md / persistent context** — architectural decisions, forbidden dependencies, conscious trade-offs, fixed before production code. Persistent context from day one.
- **Session-log pattern** — session start: revisit scope + CLAUDE.md; end: log entry (what built, which decisions, which assumptions). ~5 min docs/session = cheap insurance vs architectural drift.
- Debt marker: new AI session needs codebase re-explained; AI changes drift from original vision.

> Concept detail: `02-Knowledge/Agentic-Technical-Debt` (Brain-vault).

## Step 2 — Signal sources (where to find debt)
Use **min 4 of 8** sources, else picture is skewed.

| Source | Extract |
|:---|:---|
| **Git log analytics** | Most-changed files (`git log --pretty=format: --name-only \| sort \| uniq -c \| sort -rn \| head`) — refactor candidates |
| **Static analysis report** | Top-20 functions by cyclomatic complexity, files > 500 LOC, duplicates (jscpd, pylint) |
| **Coverage report** | Modules with coverage < 40% at > 100 LOC |
| **`dependency-audit` (if present)** | outdated/vulnerable deps |
| **PR review comments** | Search archive `[issue]` / `[blocker]` — recurring items |
| **Incident postmortems** | Root cause `infrastructure` / `code` — direct debt source |
| **Team retro** | "What hurts?" — subjective, catches what metrics miss |
| **Onboarding feedback** | Newcomer after 2 weeks — where they stumbled |

## Step 3 — Register entry format
One debt item = one entry:

```markdown
### TD-001: [short title]

- **Type:** architectural | code | dependency | test | process
- **Location:** path / module / whole repo
- **Description:** what's wrong, 2-3 lines
- **Impact** (if unfixed):
  - Dev speed: high | medium | low
  - Incident risk: high | medium | low
  - Team (onboarding, frustration): high | medium | low
- **Fix effort:** XS (< 1 day) | S (1-3 days) | M (1-2 weeks) | L (1 month) | XL (quarter+)
- **Cost of not fixing:** team hours/month (estimate)
- **Trigger to fix:** what must happen to fix it (e.g. "when coverage drops below 50%", "at next major framework upgrade")
- **Owner:** who holds context
- **Created:** YYYY-MM-DD
```

## Step 4 — Prioritization (impact × effort matrix)
```
                effort
                XS    S    M    L    XL
impact  high    🔴   🔴   🟡   🟡   ⚪
        medium  🔴   🟡   🟡   ⚪   ⚪
        low     🟡   ⚪   ⚪   ⚪   ⚪

🔴 = do now (quick win or critical pain)
🟡 = plan into sprint-roadmap, 20-30% capacity
⚪ = live with it, recheck next quarter
```
Rule: **≤ 30% engineering capacity** on tech debt. Else no features → business unhappy → next debt iteration guaranteed.

## Step 5 — Payback ROI (when to fix)
```
ROI = (cost-of-not-fixing over period) / (cost-of-fixing)

- cost-of-not-fixing = hours/month × months to next review
- cost-of-fixing = effort estimate × avg hourly rate

ROI > 3  → do it
ROI 1-3 → plan
ROI < 1 → defer (tolerating is cheaper)
```
Example:
- TD-007: outdated ORM, 4 hours/month on workarounds
- Per year: 4 × 12 = 48 hours "tax"
- Migration: ~80 hours
- ROI = 48 / 80 = 0.6 → **don't fix now**, recheck in a year

## Step 6 — Entry lifecycle
```
[new] → triaged → planned → in-progress → resolved
                ↘ accepted (live with it, consciously) ↗
                ↘ obsolete (context changed, irrelevant)
```
Quarterly: review register — what moved, appeared, became obsolete.

## Agent adds on its own
- Run `git log` analytics on repo → top-20 hot-spots
- Cross-check with `dependency-audit` if present in project
- "Debt density" metric: total fix effort / total repo LOC — for tracking over time
- Warn: if 70%+ entries have `impact: low` → register cluttered, clean up
- Propose pushing found `architectural` debts to `refactoring-plan` (via `produces_for`)

## Output file structure `Tech_Debt_Register.md`
```markdown
# Tech Debt Register: [ProjectName]

> Audit date: YYYY-MM-DD
> Next review: YYYY-MM-DD (+3 mo)

## Summary
| Type | Count | Total effort |
|:---|:---:|:---:|
| architectural | N | XX days |
| code | ... | ... |

## Top-10 by impact × effort (matrix)

## Register (TD-001 … TD-NNN)
[Entries per Step 3 format, sorted by priority]

## Accepted trade-offs (accepted debt)
[What we consciously won't fix and why]

## Metrics
- Debt density: X effort-days / 1000 LOC
- % capacity on debt: Y%
- Resolved this quarter: Z items
```

## Links
- `quality/refactoring-plan` — where `architectural`/large `code` debts go (produces_for)
- `oss-dev/dependency-audit` — signal source for `dependency` type (outdated/CVE/abandoned)
- `quality/test-strategy` — signal source for `test` type (coverage gaps, ice-cream cone)
- `operator/postmortem` — incident postmortems as root-cause debt source
- `quality/code-review` — archive of `[issue]`/`[blocker]` comments as signal source
- `system/cross-project-coordinator` — cross-project duplicates as debt/shared-package candidates

## Hard prohibitions
NEVER:
- Record "refactor X" without impact + effort — that's wishful thinking, not a debt item
- Plan > 30% capacity on debt at once
- Fix debt without trigger / ROI justification — path to over-engineering
- Delete register entries — only move to status `resolved` / `accepted` / `obsolete` (history audit)
- Audit someone else's code without team context (what they already know vs don't)
- Use tech debt as a weapon in internal disputes ("everything's bad because of [name]")

<!-- ru-source-sha256: 5e8736c18d0b8249181fb6fd057d007bbf071bbcc29fea4780622f4d43ff5e6f -->

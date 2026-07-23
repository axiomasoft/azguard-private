---
name: artifact-review
bucket: quality
version: 0.1.0
description: "Review taxonomy by artifact type: three profiles (plan/spec, documentation, code), each with a conformance constitution and dimensions; shared severity axis Blocker/Major/Minor/Nit + independent Conventional-Comments label. Activate when reviewing any artifact — pick the profile first, then measure."
risk: read
persona: quality
tags: [quality, review, taxonomy, plan, docs, code, severity, conventional-comments]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Review taxonomy by artifact type

## Activate when

- A review request arrives and the artifact is NOT just code: plan, spec, docs.
- Dispatching a review command (`task:review`, `task:plan-audit`) and a profile must be chosen.
- Writing findings and you need the shared severity+label scale instead of an ad-hoc one.

Do not use as a replacement for stack-specific code checklists — those come from
skill-conformance (`task:review` orchestrator, Stage B); this skill is the top level:
WHICH profile to measure with.

## Principle

One-size-fits-all review misses defect classes: a plan breaks in different places than code.
Separating design/doc/code review is industry reality (RAG:✅ P5.1 §7 2026-07-09); there is
no named severity standard for plan review — the scale below is our adaptation, mark it as
such. Each artifact type gets its own **conformance constitution** (what we check against)
and its own **dimensions** (what actually breaks).

## Three profiles

| Profile | Conformance constitution | Key dimensions |
|:--|:--|:--|
| **Plan/spec** | plan-protocol 2.0 schema + DoR | RAG-groundedness of load-bearing points (`RAG:✅`/`[UNVERIFIED]` markers); structural completeness (sections/statuses/folders); D-log/phase consistency; Open Questions extracted; gates defined |
| **Documentation** | docs structure canon (Diátaxis / vault canon) | canon conformance; staleness vs code (examples/routes/flags verified); plan→docs migration without loss; wiki-link integrity / broken links |
| **Code** | stack skills (skill-conformance, `task:review` Stage A/B) | correctness / security / perf + skill-conformance per applicable skill |

The constitution is not reviewer taste: a "non-conforming" finding must reference the exact
constitution rule (schema section, canon rule, `## Conformance` line of a skill).

## Shared axes: severity × label

Two **independent** axes per finding (RAG:✅ P5.1 §7, CONFIRMED: Conventional Comments label =
comment intent, severity = weight):

**Severity:**

| Severity | Meaning |
|:--|:--|
| **Blocker** | artifact cannot be accepted/merged until fixed |
| **Major** | important defect: fix now or defer by explicit decision (ticket/D#) |
| **Minor** | real but non-critical — author's call, with a trace |
| **Nit** | style/trivia; never blocks |

**Label** (Conventional Comments): `issue` / `suggestion` / `question` / `nitpick`.
Combinations are legal and informative: `issue(Blocker)`, `suggestion(Minor)`,
`question(Major)` — a question whose answer gates acceptance.

Rules: when in doubt, set severity HIGHER, don't drop findings yourself (detect ≠ gate);
every finding carries `file:line` + failure_scenario + constitution-rule reference.

## Review fixes

Fixes for findings go as **separate commits** from the review report and from each other
(P7.4 commit gate): one semantic fix = one commit `type(scope): subject` — atomically
revertible and traceable to the finding.

## Dispatch

By artifact type on input (analogous to stack detection in `task:review` Stage A):

| Artifact | Profile-implementing command |
|:--|:--|
| Plan/spec (`plans/<ID>/…`) | `task:plan-audit` — declared implementation of the plan-review profile |
| Documentation (`docs/`, README, guides) | `task:review`, doc profile |
| Code (diff/branch/PR) | `task:review`, code profile (Stage A/B/C + skill-conformance) |

Mixed input (diff touches both code and docs) — run both profiles, keep findings separated:
each finding names its profile.

## Quality checklist

- [ ] Profile picked by artifact type before reviewing, not by "read it as code" habit.
- [ ] Constitution named explicitly; every finding references its exact rule.
- [ ] Every finding: severity (Blocker/Major/Minor/Nit) + label + `file:line` + failure_scenario.
- [ ] Nit never blocks; Blocker never downgraded "to keep things moving".
- [ ] Mixed artifact split by profile, findings not blended.
- [ ] Fixes as separate atomic commits traceable to findings.

## Conformance

- Pick the review profile by artifact type: plan/spec → plan-protocol+DoR, docs → docs canon, code → stack skills.
- Every finding gets a severity from `Blocker/Major/Minor/Nit` AND a label `issue/suggestion/question/nitpick`; don't mix the axes.
- Anchor every "non-conforming" finding to the exact constitution rule (skill→rule, schema section, canon).
- Every finding carries `file:line` + failure_scenario.
- When in doubt, set severity higher; don't drop findings yourself.
- `nitpick`/Nit never blocks acceptance.
- Review fixes go as separate commits, one per semantic fix.

## Related skills

- `quality/code-review` — team code-review process/checklist (P0–P3, SLA, `[blocker]`… prefixes); this skill is the taxonomy one level up: which profile, which scale.
- `general/plan-protocol` — plan-protocol 2.0 schema + DoR = constitution of the plan/spec profile.
- `quality/tech-debt-audit` — scheduled debt audit; deferred Major findings land there.
- `general/git-commit-rules` — commit format for atomic review-fix commits.

<!-- ru-source-sha256: 0c3510affe7641c3953905e275c4fae9166a6efd88250a0ae72e774d894d8dc7 -->

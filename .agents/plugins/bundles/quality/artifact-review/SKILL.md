---
name: artifact-review
bucket: quality
version: 0.2.0
description: "Review plans, specifications, docs, or mixed artifacts against their adopted contracts."
when_to_use: "Use when reviewing plans, specs, documentation, or mixed artifacts."
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

# Artifact Review

Pick review dimensions from the artifact and requested scope. Remain read-only unless fixes
were separately authorized. Review does not automatically start a Task phase or implementation.

| Artifact | Contract and useful checks |
|---|---|
| Executable Task plan | Its adopted `general/plan-protocol` version, readiness, dependencies, decisions, acceptance, continuity |
| Other plan/spec | Requested outcome, internal consistency, feasible sequence, missing decisions and failure cases |
| Documentation | Actual code/API behavior, intended audience, working links, applicable documentation conventions |
| Code | Runtime contract, correctness, security, compatibility, relevant performance and validation |

A `plans/` path alone does not authorize `task:plan-audit`. Use that lifecycle command when an
executable Task plan audit is requested or already authorized. A bounded design/spec review
stays within that request. For mixed input, apply relevant dimensions in one review and identify
the affected artifact in each finding; no mandatory parallel reviewers or second audit pass.

## Findings

Each finding identifies a precise location, failure scenario, consequence, and supporting
observation or static reasoning. For nonconformance, cite the exact **adopted** requirement.
Generic skill advice, an unchosen architecture, or reviewer preference cannot become a gate.
Record uncertainty separately; do not claim a hypothetical scenario was reproduced.

| Severity | Meaning |
|---|---|
| Blocker | Demonstrated acceptance/safety violation that prevents accepting the artifact |
| Major | Material defect requiring repair or an explicit accepted deferral |
| Minor | Real bounded defect that does not block acceptance |
| Nit | Optional style/preference; never blocks |

Labels `issue`, `suggestion`, `question`, and `nitpick` describe intent independently of severity.
A question needs a concrete acceptance consequence to carry Major/Blocker severity. Explicit
uncertainty does not excuse a known blocker, and a stylistic suggestion never blocks.

## Close the review

Report findings first, followed by coverage and material limits. A clean bounded review means
no findings in that scope, not proof of all-system correctness. The executor may group related
repairs with shared evidence, preserving independent rollback/ownership boundaries where needed.
Recheck changed findings; a second full audit needs new scope or a concrete new concern.

See `quality/code-review` for diff mechanics and team process. Use `general/plan-protocol` only
for an adopted executable plan contract, not as a universal template for all plans or specs.

---
name: code-review
bucket: quality
version: 0.2.0
description: "Review a code diff or define team review policy; severity follows demonstrated impact."
when_to_use: "Use when reviewing a diff or defining team review policy."
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

# Code Review

For a requested diff review, stay read-only and inspect the affected contract, implementation,
and tests. For a request to establish team process, define workflow and SLAs separately; team
size, PR length, or missing review bureaucracy is not a reason to refuse a bounded review.

## Review the change

1. Identify the requested behavior and the project's adopted contracts. Inspect generated
   changes through their source/generator and check the output when it affects runtime.
2. Trace concrete failure scenarios: correctness, security, compatibility, data integrity,
   concurrency/retries, and performance on the relevant path.
3. Check whether validation reaches the changed behavior and failure branches. Missing a
   generic coverage percentage or a test for a trivial edit is not automatically a defect.
4. Check architecture/style only against applicable project decisions. A suggested skill
   pattern is not an adopted contract and cannot by itself justify a Major or Blocker.
5. Report actionable findings with location, trigger, consequence, evidence, and proposed
   direction. Separate confidence from severity; static proof is valid without a live incident.

## Severity

Use the same scale as `quality/artifact-review`:

| Severity | Meaning |
|---|---|
| Blocker | Demonstrated acceptance/safety violation that prevents accepting the change |
| Major | Material defect requiring repair or an explicit accepted deferral |
| Minor | Real bounded defect that does not block acceptance |
| Nit | Optional style/preference; never blocks |

Labels (`issue`, `suggestion`, `question`, `nitpick`) describe intent, not severity. An unanswered
question is not automatically Major. Explain the impact that makes its answer necessary.
Do not assign severity from a category alone: a security defect's weight depends on the attack
and exposure; a missing index depends on the actual query and load.

## Process without extra gates

Keep reviews proportional. Read the code before approving, never portray failed/skipped checks
as green, and respect actual branch protection and release policy. A large cohesive diff can
be reviewed in sections; a 500-line limit, two-round escalation, response SLA, mandatory second
reviewer, or human approval is binding only when the project adopted it. Seek specialist input
for an unresolved high-risk boundary, not for every edit mentioning auth.

Reuse already established evidence. Recheck repaired findings and their affected neighbors;
repeat a full audit only for a new change or hypothesis. Review findings do not authorize new
implementation, comments to external services, merge, or release.

For PHP-specific review prompts read [checklist](snippets/code-review.md).
[Severity guide](snippets/severity-guide.md) and [comment template](snippets/pr-comment-template.md)
use this same scale. `oss-dev/gh-review` supplies tool mechanics when GitHub review is requested.

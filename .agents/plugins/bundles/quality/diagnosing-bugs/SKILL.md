---
name: diagnosing-bugs
bucket: quality
version: 0.2.0
description: "Diagnose hard, flaky, or performance bugs after ordinary inspection cannot establish the cause."
when_to_use: "Use when a difficult bug lacks an established cause."
risk: draft
persona: oss-dev
tags: [debugging, diagnostics, feedback-loop, performance, testing]
requires: []
produces_for: [code-review]
outputs: []
snippets: [hitl-loop.template.sh]
adapters: [claude, cursor, fable]
disable-model-invocation: false
sha256: ""
---

# Diagnosing Bugs

Activate for an uncertain cause, repeated failed fixes, flaky behavior, or a performance
regression. An obvious statically demonstrated defect can be repaired directly with suitable
verification; this method must not block on unavailable production access or a live reproducer.

## Establish useful evidence

Inspect the affected contract and code to form a falsifiable explanation. Prefer a small
repeatable feedback loop that reaches the user's symptom: a failing test, CLI fixture, isolated
HTTP/browser script, or sanitized trace replay. Record the actual command and result. A failure
from environment setup does not reproduce the reported bug.

Keep the loop quick and deterministic where possible (time, seed, inputs, isolated storage).
For concurrency/flakiness, record attempts and observed failure rate; a low rate is weaker
negative evidence, not a reason to abandon investigation. Bound stress runs by resource risk.
Do not drive destructive effects, real payments, or external sends to increase reproduction.

When a live reproducer is unavailable, distinguish a proven static defect from a hypothesis.
Use call-path reasoning, contract tests, or a local fixture for available verification. Report
what remains unverified; ask for a missing artifact/access only when it is necessary to resolve
that uncertainty. Continue independent authorized work. Production instrumentation requires its
own actual authority and data controls.

## Test the cause

- Minimize the failing case enough to isolate the relevant variables; exhaustive minimization
  is unnecessary once the cause is established.
- Rank plausible explanations when evidence admits alternatives. Do not invent three to five
  hypotheses for a deterministic one-line fault. State the prediction before an experiment.
- Probe one discriminating boundary at a time with a debugger or targeted logs. Tag temporary
  logs (for example `[DEBUG-a4f2]`) and keep secrets/user data out.
- For performance, measure a baseline and the same workload after the change. Use timings,
  profiles, query plans, or bisection; do not claim speedups from inspection alone.

## Repair and finish

Fix the owning cause within authorized scope. Add a regression test when a meaningful seam
reaches the actual failure, and confirm fail-before/pass-after when practical. A shallow mocked
test cannot stand in for a multi-caller race or transaction guarantee. If no sound seam is
available, document that limitation and use the strongest available check without starting an
unrequested architecture redesign.

Run the original scenario and affected required checks. Remove your temporary instrumentation
and prototypes without deleting others' work. Report cause, fix, verification, and remaining
limits; continue necessary delivery. A broader architecture improvement is an optional next
recommendation after completion, not a prerequisite to the authorized repair.

For unavoidable device/manual reproduction, read
[HITL loop template](snippets/hitl-loop.template.sh) and adapt it to the actual safe environment.
Use stack testing skills for isolation and commands; `quality/test-strategy` only when the suite
strategy itself needs a decision.

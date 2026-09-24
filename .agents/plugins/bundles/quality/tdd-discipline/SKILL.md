---
name: tdd-discipline
bucket: quality
version: 0.2.0
description: "Test-first work explicitly requested or adopted by the project: one behavior per red-green cycle."
when_to_use: "Use when test-first work is requested or required by project policy."
risk: draft
persona: oss-dev
tags: [tdd, testing, red-green-refactor, vertical-slices]
requires: []
produces_for: [code-review]
outputs: []
snippets: [tests.md, mocking.md, refactoring.md]
adapters: [claude, cursor, fable]
disable-model-invocation: false
sha256: ""
---

# Test-Driven Development

Use when the owner asks for test-first development or the project's adopted workflow requires
it for this change. Merely mentioning a feature, bug, or integration test does not activate TDD.
The authorized scope supplies approval: do not ask again for the same interface, behaviors, or
plan. Clarify only unresolved product/API decisions that materially change the result.

## Vertical cycle

1. Read the relevant public contract and existing tests; use `CONTEXT.md` or ADRs when they
   define the affected domain. Choose a behavior whose failure matters to the task.
2. Write one focused test at a seam that reaches that behavior. Run it and verify that it
   fails for the intended reason, not a broken environment or unrelated setup.
3. Implement enough to satisfy the behavior and preserve the existing contract. Run the test.
4. Repeat for the remaining material cases, learning from each completed slice. Avoid writing
   a large speculative suite before the first working implementation.
5. Refactor scoped duplication after green, then rerun affected checks. Stop when the requested
   behavior and required validation are complete; no automatic architecture redesign follows.

## Test quality

Prefer assertions on observable behavior that survive internal refactors. An internal seam is
useful when it is itself a stable component contract. Database assertions can be necessary to
verify persistence, atomicity, isolation, or an outbox; call counts can prove non-idempotent
side effects are not repeated. Match the assertion to the failure, not a universal mock ban.

Use real collaborators at the integration boundary being tested, and fakes for unrelated
external services, time, and randomness. A mocked integration cannot establish that the real
DB, queue, or provider contract works. Preserve isolated test storage and payment sandboxes;
do not turn a test-first loop into live production effects.

Read [tests](snippets/tests.md) for behavioral examples and
[mocking](snippets/mocking.md) when choosing a boundary. They are local adaptations of the
recorded source, governed by the scoped rules above. [Refactoring](snippets/refactoring.md)
contains optional prompts for cleanup after green, not a requirement to expand scope.

If the environment prevents an honest red/green check, report which evidence is missing and
perform available verification. Do not claim a skipped test passed. Use
`quality/test-strategy` only when suite strategy itself needs work; use stack-specific test
skills for the commands and isolation requirements actually involved.

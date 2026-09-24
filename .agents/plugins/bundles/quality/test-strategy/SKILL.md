---
name: test-strategy
bucket: quality
version: 0.3.0
description: "Design or revise a test suite strategy; choose checks by behavior and risk, not fixed percentages."
when_to_use: "Use when defining or revising test-suite policy."
risk: draft
persona: quality
tags: [quality, testing, coverage, validation, ci, architecture, pest]
requires: []
produces_for: [code-review, mutation-testing, playwright-e2e]
outputs: ["docs/03_Dev/Test_Strategy.md"]
snippets: ["test-pyramid.md", "pest-arch-test.php", "phpunit-testsuites.xml"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Test Strategy

Use when defining or revising a suite's coverage, test boundaries, isolation, runtime, or CI
policy. An ordinary fix uses existing checks plus the smallest meaningful regression coverage;
it does not require this strategy document, a finished MVP, or an approved architecture file.

## Choose evidence by failure

| Changed behavior | Useful evidence |
|---|---|
| Calculation or branching logic | Unit cases for boundaries and invariants |
| Persistence, query, transaction | Integration with isolated storage and the relevant DB semantics |
| Authorization or tenant visibility | Allowed and denied access at the actual entry boundary |
| Queue, payment, external effect | Contract/fake checks plus retry, idempotency, and failure cases |
| User journey spanning components | Focused API/browser integration or E2E |
| Migration | Isolated representative data; forward/rollback or recovery evidence by risk |
| Text or mechanical reversible edit | Structural/link/lint checks where useful; no mirrored test |

A single well-placed test may cover several layers. Do not require unit + integration + E2E
for every feature. Reuse valid checks; broaden after new changes, failures, or a concrete gap.

## Suite design

Prefer fast feedback and reliable assertions. The familiar 70/20/10 pyramid is an illustration,
not an enforced distribution. Choose coverage targets from uncovered failure risk and cost;
90% domain or 80% application coverage are possible team choices, not default merge gates.
Line coverage cannot prove correctness. Mutation testing can help a critical module when
ordinary coverage leaves an actual uncertainty.

Test real behavior at the boundary under examination. Mocking internal collaborators is
appropriate for a focused unit contract but does not verify their integration. For DB or queue
integration, use the production-relevant semantics in an isolated test environment. SQLite
cannot prove PostgreSQL locking behavior. Use sandbox/fakes for payments and external sends.
Never direct cleanup, migration, or destructive fixtures at development or production storage.
Keep per-test data isolated; do not make success depend on test order or shared mutable state.

Use factories, small fixtures, or approved anonymized representative data according to the
failure. Production-derived data still requires the project's privacy/access controls.

## Adopt policy explicitly

Record the suite owners, relevant commands, environments, expected runtime, required CI checks,
and any accepted exceptions. CI gates come from adopted project/release policy. Mark failed,
flaky, and skipped checks honestly; quarantine needs an owner and loss-of-coverage explanation.
Decide whether E2E belongs pre-merge or later from release risk, not a blanket nightly rule.

For a PHP layered project, read [suite example](snippets/phpunit-testsuites.xml) and
[architecture example](snippets/pest-arch-test.php) only when configuring those mechanisms.
Architecture assertions encode the project's chosen boundaries; they must not impose Actions,
DTOs, `readonly`, or repository layers on another architecture. The
[pyramid illustration](snippets/test-pyramid.md) is optional background.

Write or update the existing strategy document when that is the task. Include concrete
commands and scenario coverage, not mandatory percentages of development time. For execution,
route to the relevant stack testing skill and its environment/isolation preflight.

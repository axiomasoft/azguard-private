# P1.4 review round 5 (local re-review)

Reviewer: codex/gpt-6.1-sol, reasoning effort high, read-only sandbox (`codex exec -s read-only`). Candidate: working tree at the time of the round.

- **Minor · High confidence · [SourceScan.php:466](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:466)** — The DNF fix introduces false class references after expression parentheses. Valid code `namespace AzGuard\Authorization; const ScopeEligibility = 1; function flags(): int { return time() | ScopeEligibility; }` now reports `AzGuard\Authorization\ScopeEligibility` as a class reference: `operandBefore()` returns `null` for the empty call parentheses. The oracle assigns that class to `scopes`, causing a false I2 violation in a core file. Independently reproduced; `(LEFT) | ScopeEligibility` fails similarly. Distinguish parentheses in type declarations from expression parentheses.

VERDICT: FINDINGS

## Executor response

finding: false class after expression parentheses `time() | Const`; operandBefore now returns type-group only for a non-empty `(names & …)` group in a type position. Fixed with a regression test.

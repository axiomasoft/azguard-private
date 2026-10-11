# P1.4 review round 2 (local re-review)

Reviewer: codex/gpt-6.1-sol, reasoning effort high, read-only sandbox, session 01a12777-bfa2-73e0-9697-54ec7a2a46c0. Candidate: after the round-1 correction.

- **Major · High confidence · [SourceScan.php:347](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:347)** — The constant filter suppresses genuine class references. In `#[LocalTag, ModuleTag] class Probe {}`, unqualified `ModuleTag` follows `,` and precedes `]`, so it is discarded despite attribute tracking. With a module-owned `ModuleTag` and no import, I2/I3 misses the dependency. The same filter drops a second `implements` interface and a single unqualified `catch` type.

- **Minor · High confidence · [SourceScan.php:367](/home/vostrikov/projects/packages/azguard/tests/Arch/SourceScan.php:367)** — Finding 6 remains partially unfixed. In `namespace AzGuard\Authorization; const ScopeEligibility = 1; function read() { return ScopeEligibility | 2; }`, the following `|` prevents constant filtering. The scanner resolves the constant to the module-owned class `AzGuard\Authorization\ScopeEligibility`, producing a false I2 violation.

Both findings follow directly from static token-path analysis. PHP repros were blocked by `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`.

VERDICT: FINDINGS

## Executor response

Both findings fixed after the round (SourceScan::isConstantRead split into expression-only and ambiguous contexts; extends/implements/trait-use clauses, attribute lists and catch types are never constants) with a regression test in OwnershipScanTest. Completed-round limit reached (2): no third round without a new bounded owner request.

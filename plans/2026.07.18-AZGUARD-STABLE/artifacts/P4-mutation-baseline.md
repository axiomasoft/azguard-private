# P4 mutation baseline — 2026-07-22

## Result

No MSI/coveredMSI baseline was produced. Thresholds remain unchanged:

| Package | Existing minMsi | Existing minCoveredMsi | Measured MSI | Measured coveredMSI |
|:--|--:|--:|:--|:--|
| core | 70 | 80 | unavailable | unavailable |
| filament | 60 | 75 | unavailable | unavailable |
| context | 65 | 80 | unavailable | unavailable |

## Evidence

Cold Xdebug coverage was generated in GitHub Actions and all Pest tests passed.
Infection 0.34 then stopped before mutating because Pest's coverage trace uses
`P\\Tests\\…` test identifiers that cannot be resolved in Pest's JUnit output:

| Run | Mode | Result |
|:--|:--|:--|
| 29893995627 | full advisory on `a1fac5e` | core/context/filament each exit 1 with `TestNotFound` |
| 29893994436 | PR diff gate on `a1fac5e` | core/context/filament each exit 1 with `TestNotFound` |

Representative raw error: `Could not find any information for the test
"P\\Tests\\Feature\\…" in …/build/coverage/xml/junit.xml`.

The workflow job is green for the advisory run only because its Infection step
uses `continue-on-error`; that is not a mutation result and must not be read as
a baseline.

## Ratchet decision

No `minMsi` or `minCoveredMsi` value was changed. Raising a threshold without a
valid measured score would violate the P4.5 ratchet invariant. Switching to
Pest-native mutation is explicitly excluded from P4.5, so the runner mismatch
is recorded as an escalation for a separately scoped decision.

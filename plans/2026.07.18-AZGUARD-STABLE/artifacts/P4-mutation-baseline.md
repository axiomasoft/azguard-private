# P4 native Pest mutation baseline — 2026-07-22

## Result

Fresh Xdebug coverage in CI ran Pest's bundled native mutator for each package.
Pest reports one covered mutation score; the blocking threshold is
`floor(score) - 2` and is never lower than the historical gate.

| Package | Historical threshold | Tested mutations | Measured score | New blocking threshold |
|:--|--:|--:|--:|--:|
| core | 70% | 1337 | 100.00% | 98% |
| filament | 60% | 154 | 100.00% | 98% |
| context | 65% | 124 | 100.00% | 98% |

## Evidence

GitHub Actions run [`29896009322`](https://github.com/axiomasoft/azguard-private/actions/runs/29896009322)
on commit `3cb2f2d` completed all three blocking matrix jobs successfully:

| Package | Job | Coverage duration | Mutation duration |
|:--|:--|--:|--:|
| core | `88846227314` | 33.32s | 67.59s |
| filament | `88846226512` | 29.36s | 7.63s |
| context | `88846226483` | 31.82s | 5.33s |

`bin/mutation-gate.sh` runs each package with fresh Xdebug coverage,
`--covered-only`, its reviewed inline exclusions, and `--parallel --processes=4`.
The preceding Infection 0.34 runner was rejected because it could not map Pest
4 JUnit test identifiers (`TestNotFound`) before running mutations; Pest-native
mutation removes that incompatible hand-off.

## Ratchet decision

All measured scores are 100.00%, so the gate now enforces 98% for core,
filament, and context. This raises every historical package threshold and
preserves a two-point buffer for mutation-run variability. CI is blocking in
every mode; local runtimes without pcov/Xdebug report an explicit honest skip.

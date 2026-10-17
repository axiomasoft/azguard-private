# P3.4 gap matrix: comparison x invariant

Cell = existing `file:test` or the new test of this item. No cell is `missing`.

| Comparison | I1 unrelated grant | I2 expiry (`expires_at == now` denies) | I3 veto vs wildcard/superadmin | I4 error never allows | I5 SQL ⊆ scalar | I6 pagination |
|:--|:--|:--|:--|:--|:--|:--|
| scalar ↔ batch | `Parity/InvariantGapTest.php:I1` | `EnginePropertiesTest.php:P7`, `Batch/DecideManyParityTest.php` | `AuthorityDispatchTest.php:policy false vetoes a qualified superadmin`, `EnginePropertiesTest.php:P12` | `EnginePropertiesTest.php:P8` | n/a | n/a |
| batch ↔ decideMany | `Parity/InvariantGapTest.php:I1` (runner uses `decideMany`) | `Batch/DecideManyParityTest.php:matches scalar decisions for 200 seeded ordered batches` | `Batch/DecideManyBeforeDenyTest.php` | `Batch/DecideManyDefinitionErrorTest.php` | n/a | n/a |
| PHP predicate ↔ SQL visibility | `Parity/InvariantGapTest.php:I1` (sql path) | `Visibility/ParityPropertyTest.php:P14` | `Visibility/BeforePredicateTest.php:uses policy as a veto…` | `Visibility/FailureTest.php` | `Parity/ParityMatrixTest.php:I9`, `Visibility/ParityPropertyTest.php:P14` | `Parity/InvariantGapTest.php:I6` |
| SQLite ↔ pgsql/mysql/mariadb | runs through `composer test:db -- <driver>` over the same files (the suite is driver-agnostic) | same | same | same | same | `Parity/InvariantGapTest.php:I6` (also on the driver lanes) |
| cache off ↔ on | `Authorization/Cache/IsolationTest.php`, `OwnMutationTest.php` | `EnginePropertiesTest.php:P7` (cache `array`, warm read) | `Cache/…` do not change verdicts: `EnginePropertiesTest.php:P5` | `EnginePropertiesTest.php:P8` | n/a | n/a |
| model subject ↔ SubjectRef | `Parity/InvariantGapTest.php:model subject = SubjectRef` (SQL) | `Authorization/SubjectResolverTest.php` | `Authorization/SuperAdminTest.php` | `Authorization/SubjectResolverTest.php` | `Parity/InvariantGapTest.php:model subject = SubjectRef` | `Parity/InvariantGapTest.php:I6` |

## New tests

`tests/Feature/Parity/InvariantGapTest.php` (zone `visibility`), seeds 1-3 of `ParityWorld`, every message names its seed:

- `I1 an unrelated grant changes no decision on scalar, batch or SQL`
- `I6 the pages of a visibility query are the unpaged set without repeats`
- `a model subject and a SubjectRef subject see the same list`

Run time of the file: about 13 s (budget 60 s).

## Not asserted

- I2 boundary `expires_at == now`: asserted by `EnginePropertiesTest.php:P7` (at and after expiry denies).
- Driver-lane runs (pgsql, mysql, mariadb) are slow runs left to CI (owner: fast runs only).

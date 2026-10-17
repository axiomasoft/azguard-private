# P3.6 gap matrix: failure points and concurrency scenarios

## Failure points

| Point | Test |
|:--|:--|
| DB exception before/after the panel-state update | `tests/Feature/Changes/TransactionTest.php` (failed child subtree, retry after concurrency error), `tests/Engines/ChangeRaceTest.php` (real deadlock retry, nested rollback) |
| Audit/journal participant failure | `tests/Feature/Changes/FailureSemanticsTest.php` (new) |
| Cache invalidation failure | not asserted: store belongs to the host; documented as a limitation in `docs/advanced/consistency.md` |
| Listener failure after commit | `tests/Feature/Changes/FailureSemanticsTest.php` (new) |
| Replica lag | `tests/Engines/ReplicaLagTest.php` |
| Redis unavailable | not asserted (see above); `tests/Feature/Authorization/Cache/RedisStoreTest.php` covers the live store |
| Resolver throws | `tests/Feature/Changes/FinalValidationTest.php`, `tests/Feature/Authorization/Properties/EnginePropertiesTest.php:P8` |
| Policy throws | `EnginePropertiesTest.php:P8` (deny, never allow) |
| Malformed runtime permission | `tests/Feature/Changes/WildcardTest.php`, `tests/Unit/Fuzz/GrammarFuzzTest.php` |
| Expiry boundary | `EnginePropertiesTest.php:P7` |

## Concurrency scenarios (pgsql, mysql, mariadb, group `engines`)

| Scenario | Test |
|:--|:--|
| grant ∥ grant, same subject | `tests/Engines/ChangeRaceTest.php:V101 serializes grant ∥ grant` |
| grant ∥ revoke, same identity | `tests/Engines/ChangeRaceTest.php:P3.6 grant ∥ revoke of one identity ends in one serial order` (new, both orders) |
| role grant ∥ permission grant | `tests/Engines/DynamicActionRaceTest.php:P3.6 role grant ∥ permission grant on one panel` (new, both orders) |
| expiry prune ∥ decision | `tests/Engines/JournalPruneRaceTest.php`, `tests/Engines/RevocationRaceTest.php` |
| bulk import ∥ interactive edit | `tests/Engines/ManagerRaceTest.php`, `tests/Engines/ChangeRaceTest.php:V101 C13` |
| cache read during commit | `tests/Engines/RevocationRaceTest.php:V18 R52` |
| two panels on one storage | `tests/Engines/MutateDeadlockTest.php`, `tests/Engines/StorageIsolationTest.php` |
| deadlock retry | `tests/Engines/MutateDeadlockTest.php`, `tests/Engines/ChangeRaceTest.php:V101 retries the root after a real deadlock` |

## Runs of the new engine tests

`composer test:db -- <driver> --engines --filter="P3.6"` with docker services, 2026-10-17, HEAD at run time:

| Driver | Result | Duration |
|:--|:--|:--|
| pgsql | 4 passed, 22 assertions | 6.3 s |
| mysql | 4 passed, 22 assertions | 15.5 s |
| mariadb | 4 passed, 22 assertions | 8.6 s |

The full engine lanes are slow runs left to CI (owner: fast runs only).

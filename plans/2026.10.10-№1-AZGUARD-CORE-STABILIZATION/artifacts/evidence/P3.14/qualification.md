# P3.14 qualification

Base HEAD 9788f87c18bfbff57b733d1709ed655e922ad6cf plus the P3.14 changes (docs, MigrationGuideTest, InvariantRegisterTest, invariants.json). PHP 8.4.26 via shim. The working tree also held owner WIP (tests/**, phpstan.neon, deleted tests/Fixtures/Testing/KitCase.php) that is not part of the commit.

| Check | Result |
|:--|:--|
| V1 | exit 0 (11 tests) |
| V2 tests/Arch | exit 0 |
| V3 Parity, InactiveCost, Regression | exit 0 |
| V4 | rector and api:check exit 0. phpstan and pint on a clean HEAD worktree (+ the two edited tests) exit 0. In the owner-dirty tree phpstan reports trait.unused for InteractsWithAzGuard (owner removed KitCase.php and its phpstan.neon entry) and pint reports the untracked local var/perf/prepend.php; both are owner/local state, not the item |
| V5 type coverage | exit 0, 99.9 % |
| V6 composer test | exit 0, 4795 passed |
| V7 test:db --parallel pgsql/mysql/mariadb | exit 0, 762 passed each |
| V8 test:db --engines pgsql/mysql/mariadb | exit 0 (71 each) |
| V9 consumer-fixture full and core-only | exit 0 |

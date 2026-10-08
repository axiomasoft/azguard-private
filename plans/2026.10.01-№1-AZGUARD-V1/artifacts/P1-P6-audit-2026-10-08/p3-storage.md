# P1–P6 audit: storage P3, transaction safeguards P4.20, testing kit P6.7

Date: 2026-10-08. Scope: completed work only; fixes applied without a full suite, coverage, mutation testing, fleet qualification or an engine matrix. No commits. Other agents' files were preserved.

## Findings and repairs

### S1 — major, P6.7 / P4.20: advertised RefreshDatabase support did not work

**Scenario.** A Laravel test using `RefreshDatabase` and `InteractsWithAzGuard` grants a permission, then calls the documented protected route. The write succeeds, but the authority read treats Laravel's isolation transaction as an unknown transaction and returns `source_error`. Previously the kit tests used `DatabaseMigrations`; the sole transaction test asserted this denial rather than exercising the promised integration. This confirms P6 review F1.

**Evidence.** `StorageReadSession::assertNoTransaction()` rejected every non-AzGuard transaction. P6.7 scope and dossier 12 §7 require the kit to work with RefreshDatabase while executing the real cache. Local Laravel sources show `RefreshDatabase::beginDatabaseTransaction()` installs `Illuminate\Foundation\Testing\DatabaseTransactionsManager`, tracks the wrapping root and runs lifecycle trait setup after starting it. Its callback manager distinguishes the wrapping transaction from application savepoints.

**Fix.** The kit's Laravel lifecycle method explicitly installs `Testing\RefreshDatabaseBaseline` through the internal `Storage\AuthorityReadBaseline` contract. Installation requires the genuine Laravel testing manager and the `testing` environment. The adapter captures only roots that already exist during setup. Recognition requires the exact manager, transaction record, write PDO, execution Fiber, an active PDO transaction, Laravel level 1, and exclusion from normal callback transactions. A fresh unrelated transaction cannot reuse an old registration. The session pins the baseline write handle, and guards every subsequent use.

The cache/fence still execute. Each captured baseline receives a random namespace included in the authority cache identity. Consequently rolled-back grants cannot contaminate a subsequent test that reaches the same incarnation/version. There is no production provider binding, no `Storage -> Testing` dependency and no production `APP_ENV=testing`-only bypass. Both new implementations and the interface are internal; `@internal` was placed on its own docblock line for the manifest scanner.

**Regression evidence.** The documented route test now uses real `RefreshDatabase`; ordinary grants, superadmin, scope restrictions, denial, the `testing` actor, and Event::fake all pass. Further regressions cover shared-cache hits, post-change invalidation, exact root rollback/restart, replaced manager/PDO/environment/Fiber, denial in an extra application transaction, publication only after that transaction commits, discarded callbacks/version on rollback, and a shared-cache entry from an earlier rolled-back root with exactly the same incarnation/version in the next root. Existing production unknown-transaction and joint-authority transaction tests also pass.

**Limits.** The explicit kit adapter recognizes only Laravel's wrapping baseline, not an arbitrary transaction above it. A developer who replaces/restarts a test wrapper must run the kit lifecycle again. These are intentional fail-closed boundaries. Laravel 11/12 consumer installs were not rerun; integration was checked against installed Laravel 13 sources and tests.

### S2 — major validation defect, P6.6 / P4 helper: flaky MariaDB lock observer

**Scenario.** P6 review F2 recorded StateResetRace's first MariaDB acceptance run failing to observe a waiting competitor; three diagnostic reruns passed. The helper parsed human-readable `SHOW ENGINE INNODB STATUS`, looking for a specific thread/table text block, so failure to parse was indistinguishable from failure to serialize.

**Fix.** MariaDB now observes the structured `INNODB_LOCK_WAITS -> INNODB_TRX -> INNODB_LOCKS` relationship and requires the exact worker connection id, `LOCK WAIT` state and expected `azg_panel_state` table. Polling leaves 120 ms between snapshots. The prior 10 ms interval must not be reused with these views: MariaDB 10.11 refreshes the shared snapshot only after it has been unread for more than 100 ms. Timeout diagnostics now identify the worker connection rather than calling every operation a revoke.

**Primary sources, read 2026-10-08:** [MariaDB INNODB_LOCK_WAITS columns](https://mariadb.com/docs/server/reference/system-tables/information-schema/information-schema-tables/information-schema-innodb-tables/information-schema-innodb_lock_waits-table); [MariaDB 10.11 trx0i_s.cc](https://github.com/MariaDB/server/blob/10.11/storage/innobase/trx/trx0i_s.cc), `CACHE_MIN_IDLE_TIME_NS`, `can_cache_be_updated`, and `trx_i_s_cache_end_read`. The source explains why rapid polling can retain the old snapshot; it does not prove the precise origin of the historical text-parser failure.

**Validation.** Existing healthy MariaDB 10.11.19 container, loopback port 23307, database verified as `azguard_test`. No services started/reconfigured. One authorized run with `timeout 45`: StateResetRace **3/3 passed, 26 assertions, 2.511 s**. This exercises both reset/grant lock orders and reset versus cached reads. Log: `p3-mariadb-reset-race.log`.

**Limits.** This is one focused acceptance run after replacing the observer, not a repeated stress proof or a new engine-matrix qualification. The existing 10-second deadline remains a real failure if the wait is not observed.

### S3 — minor, P6.7: plugin contract tests rejected valid dependent/stateful plugins

**Scenario.** `pluginKeptOffAPanelLeavesNoTraceThere()` omitted `azguardCompanions()` when constructing the global plugin set, so a valid plugin implementing `DependsOnPlugins` failed with a missing dependency. Its comparison baseline also omitted companion contributions that legitimately remain on the excluded panel. `bootDoesNotChangeThePanel()` separately called `boot()` on a fresh, unregistered plugin with an empty dependency context. Additionally, `ContractWorld` did not bind its new registry into the container until after plugin boot, so a valid plugin querying its registry during boot saw another registry.

**Fix.** Companions are included in the exclusion fixture and baseline. New internal `ObservedPlugin` observes the actual single `register -> boot` lifecycle on the compiler's own cloned plugin, preserving dependencies and registration state. `ContractWorld` binds its active registry before freeze/boot and restores the previous registry if building fails.

**Regression.** `DependentPluginContractTest` runs all five plugin contract tests on a stateful dependent plugin whose companion contributes a hook. It checks registration-before-boot, exactly one boot per clone, correct dependency context and the active registry's identical panel instance. Existing negative plugin fixtures still fail as expected.

### S4 — minor, P6.7: contract write observers missed commented and CTE writes

**Scenario.** Both contract hooks/restrictions and `ObservedPipe` classified writes using a regex anchored to a leading DML/DDL keyword. `/* request tag */ INSERT ...`, `-- comment\nINSERT ...` and `WITH ... UPDATE ...` changed data without appearing in the write log. An extension could therefore pass its no-writes contract test despite issuing its own SQL writes.

**Fix.** A shared internal `WriteLog::isWrite()` now removes comments and quoted values/identifiers before recognizing ordinary or CTE DML/DDL. It also handles executable MySQL comment prefixes, PostgreSQL dollar-quoted strings and row-locking `FOR UPDATE` syntax. Both observers use the same classifier.

**Regression.** Real SQLite statements cover block-comment INSERT, line-comment INSERT and CTE UPDATE; a CTE SELECT containing the literal `update` and a comment containing `insert` remains a read. A pipe with a commented SQL write after the real writer now triggers the contract assertion. This observer is a test aid, not a general SQL security boundary; SQL stored functions with hidden side effects are outside this classifier's guarantees.

## Coverage and validation

Read P3.1–P3.4 rules, D12, P4.20 safeguards, P6.7 requirements, previous P3/P6 findings and DEVELOPMENT. Reviewed current `Storage`, `StorageMutation`, `AuthorityTransaction`, `StorageReadSession`, `StorageRegistry`, schema/host-key helpers, the migration entry point, guarded models/builders, grant identity/casts, field serialization, kit/fake and six contract suites. Existing storage tests cover root/nested rollback, touch coalescing, after-commit publication, bounded retries, no retry after a committed callback failure, direct-write guards, storage binding, custom model declarations, identity canonicalization, fields, schemas and multiple storages.

| Check | Observed result | Evidence |
|---|---|---|
| Storage + Unit/Storage + Feature/Testing + production TransactionGuard/HostTransaction | 701 passed, 3538 assertions, 6.859 s; no skips | `p3-targeted.log` |
| Final RefreshDatabase regressions, including same-revision cache isolation | 17 passed, 66 assertions, 0.282 s | `p3-refresh-database-final.log` |
| Final Contracts + Feature/Testing after contract-kit repairs | 131 passed, 504 assertions, 1.188 s; no skips | `p3-contracts-final.log` |
| MariaDB StateResetRace, single targeted run | 3 passed, 26 assertions, 2.511 s | `p3-mariadb-reset-race.log` |
| Pint, only the 14 changed/new owned PHP files | passed | tool output |
| Owned-path `git diff --check` | exit 0 | tool output |

SQLite commands used `env -u AUTHORITY_REPLICA_TEST APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:` and `timeout 60`; TestCase resolves SQLite to `:memory:`. No full suite, full static analysis, coverage, mutation or engine matrix was run. The storage run predates only the later contract-helper changes and extra same-revision kit test; those are covered by the final 131-test run. Root owns final manifest/arch/integration checks.

The first supplemental contract run reported 99/100 because the new regression instantiated `HookProbe` with `null` instead of its required factory Closure. This test construction mistake was repaired; final 131/131 passed. The original failed log is retained as `p3-contracts.log`, not counted as a successful gate.

## Existing P3 specification discrepancy

P3 review R2 remains an implementation-versus-wording discrepancy: Laravel's PG/MySQL/SQLite schema grammars do not honor the requested explicit names for primary keys. Actual primary keys use the engine's native names; current identities and PostgreSQL's 63-byte limit remain intact. This audit did not change portable DDL to chase cosmetic PK names. Root was notified to reconcile the normative naming wording/evidence rather than claim explicit names are implemented. No additional product defect was established in the reviewed P3 model/migration paths.

Final reconciliation by root: accepted decision D25 records engine-native physical primary-key names and retains the required columns, uniqueness and name-length budget. This closes the wording discrepancy without new DDL or data migration. D25 also records the narrow RefreshDatabase baseline contract and excludes raw PDO rollback/restart that bypasses Laravel transaction bookkeeping.

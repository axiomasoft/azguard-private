# P4 finding dispositions

Rechecked on 2026-10-31 against HEAD `8c25b2a7` and the local changes accompanying this document, preserving
the intervening P1–P3 corrections. The previous execution was commit `87fb6255` against `c16fdefa`;
its original outputs remain under `build/qualification/p4-grok/`. Finding numbers below are the order in
each raw result's `findings` array, not a new severity ranking. Inputs are the final results in all five raw
`P4*.jsonl` files and all four independent triages under the audit's `artifacts/review/grok-phases` directory.
All 27 findings are dispositioned below; this is coverage of the reported findings, not of all P4 code.

This is a bounded follow-up, not complete P4 qualification. `P4.jsonl` explicitly did not review P4; the other
reviews used selected patches, and P4.b skipped their middle. Neither the original empty finding list nor
passing the focused tests establishes full Grok coverage. V1 remains the coordinator's check.

The product contracts used here are SEC-07/D10 and SEC-08/D8 in `docs/advanced/security.md`: primary concrete
record checks precede sensitive effects; host callers authorize assignments separately from actor attribution.
Neither contract promises atomicity with concurrent changes, authorization of arbitrary trusted PHP callbacks,
or automatic migration of host identities.

## P4.a

| Finding | Disposition | Evidence |
|---|---|---|
| 1. Special authorization for the name `export` | Confirmed conditional authorization defect for ordinary host callbacks using that name. Fixed by testing `ExportAction`/`ExportBulkAction` classes for both overall and individual checks; custom actions retain their own permission and primary-read requirement. | `packages/filament/src/Internal/Authorization/AuthorizesActions.php::ability`, `exports`, `configure`; `ExportDownloadAuthorizationTest.php`: custom callback refusal, own-permission/primary positive and negative cases, renamed genuine export cases. |
| 2. Select-all denylist lets a callback reach hidden rows | The unrestricted `Model::whereKeyNot()` callback is unsafe host code, not a demonstrated bypass of the validated selection. Kept supported select-all, added scalar-key validation and negative malformed-key cases; documented that callbacks consume the selected collection/query. | `AuthorizesActions.php::__invoke`, `keys`; `StaleBulkAuthorizationTest.php`: mixed explicit keys are refused, empty-denylist selected query keeps hidden row 3, ordinary deselections still work; `docs/filament/security.md`. |
| 3. Assignment example reads stale host state | Reproduced with separate SQLite read/write PDOs: after actor membership removal on primary, the example still applied an assignment. Fixed actor/target/tenant/project/membership reloads to write PDO and explicit `onPrimary()` role qualification. Project/target failures previously occurred only deeper in the writer. | `tests/Fixtures/Changes/AuthorizedRoleAssignments.php::change`, `member`; `AuthorizedAssignmentsExampleTest.php`: split-PDO cases for actor membership, target membership, project state and authority, with unchanged grants/revisions/events/audit on refusal. Original red output: `build/qualification/p4-grok/assignments-red.log`. This is a split-PDO regression, not real server replication qualification. |
| 4. Surface method with no serving panel | Not established as a package vulnerability. Enforcement is deliberately attached to the serving Filament panel; null/unmanaged context is outside that boundary. Concrete effects in host methods still require their own checks. No global deny was added. | `FilamentContext.php::serving`, `of`; `RefusesSurfaceCalls.php::__invoke`; `FilamentGate.php::page`, `widget`; `docs/filament/security.md`. |
| 5. Earlier download controller binding survives `bindIf` | Confirmed integration behavior, accepted as a trusted host controller override. The security guide already assigns replacement controllers row authorization duties; clarified that this includes pre-existing bindings. Replacing it unconditionally would silently discard a deliberate host replacement. | `AzGuardFilamentServiceProvider.php::register`; `docs/filament/security.md`; existing owner/legacy download refusal tests in `ExportDownloadAuthorizationTest.php`. |
| 6. Discarded `ModelKey::where()` result | Rejected: both branches mutate the supplied builder. Serialization before the records restriction does not remove it from the local preflight query; chunk jobs recheck records. Added the distinguishing case with hidden selected key 3 while row 1 remains authorized; no headers or chunks are produced. | `packages/core/src/Support/ModelKey.php::where`; `ChecksPrepareCsvExport.php::hasAuthorizedRecord`; `ExportDownloadAuthorizationTest.php`: hidden selected keys case. |
| 7. Nested queries with an unbuffered MySQL cursor | **Reproduced and fixed in this recheck.** On disposable MySQL 8.4.13 with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=false`, otherwise authorized bulk callbacks, export preflight and preparation were refused. All three scans now use bounded `each(..., 100)` queries that finish fetching before nested authorization. Bulk still checks the complete selection before the callback; exports still stop after the first allowed row. This was an availability defect, not a demonstrated authorization bypass. | `AuthorizesActions.php::__invoke`, `AuthorizesExports.php::__invoke`, `ChecksPrepareCsvExport.php::hasAuthorizedRecord`; `StaleBulkAuthorizationTest.php`: 103 selected rows, allow and denial on the second page; `ExportDownloadAuthorizationTest.php`: preflight/preparation allow and revoked controls with unbuffered PDO. `build/qualification/p4-unbuffered-red.log`: 3 failures of 6 before the fix; `p4-unbuffered-final.log`: all 6 pass. |

The select-all-plus-explicit-keys fix already present at HEAD was preserved. The raw review's accepted residuals
remain: host TOCTOU coordination, trusted low-level writes/system actors, replacement-controller responsibility,
host authorization of concrete surface effects, and chunks legitimately producing no file after refusal.

An additional reproducible compatibility defect surfaced in the positive renamed-export test: Filament's
`ExportBulkAction` defaults to fetching IDs, while AzGuard installs an individual callback taking `Model`.
The action now fetches models before that callback runs. The renamed genuine bulk-export case proves a checked
preparation job is queued instead of the previous TypeError (`filament-relation.log`).

## P4.b

| Finding | Disposition | Evidence |
|---|---|---|
| 1. Carbon time leaks from expiry test | Rejected: file-level `afterEach(W::reset())` calls `VisibilityWorld::reset()`, which clears Carbon even after a failed assertion. | `QueryParityContractTest.php` hooks; `tests/Fixtures/Visibility/VisibilityWorld.php::reset`. |
| 2. Empty batch can vacuously pass | Not a reproduced engine defect: `Authorizer::decideMany()` indexes every selected request via `DecisionSet::get()`, which throws for missing decisions. Added an explicit cardinality assertion as a local negative control. | `Authorizer.php::decideMany`; `DecisionSet.php::get`; `QueryParityContractTest.php`: invalid/incomplete contribution cases. |
| 3. Scalar decisions mask batch implementation via cache | Rejected as stated: scalar and batch execute their pipelines; contributions, not completed authorization decisions, are memoized. New subject-2 finite-universe checks run batch before scalar resource checks. This does not claim every contribution read is cold. | `Authorizer.php::evaluate`, `BatchEvaluation.php::group`; `QueryParityContractTest.php::exerciseFolderTenantMode`; `ScopedSourceWorld.php::relations`. |
| 4. Relation tenant-wide grant lacks visibility/pre-grant controls | Confirmed test gap, fixed. Subject 2 denies in A before the grant; a mapped finite project universe checks scalar/batch/visibility before and after the grant. A's tenant-wide grant allows projects 7 and 8 only in inherit; B's real relation allows project 9 independently, then detaching it denies in both modes. | `ScopedSourceWorld.php::relations`; `RelationProjectResource.php`; `RelationTenantContractTest.php`. |
| 5. Relation scenario lacks storage schema | Rejected: `exerciseRelationTenantMode()` calls `ScopedSourceWorld::seed()`, which drops and creates storage schema before inserting grants. | `RelationTenantContractTest.php::exerciseRelationTenantMode`; `ScopedSourceWorld.php::seed`. |
| 6. Membership test lacks positive control | Confirmed test gap, fixed with allow assertions on scalar, batch and exact visibility before membership is removed. All three deny afterwards. | `QueryParityContractTest.php`: `denies a database grant when tenant membership is absent in scalar batch and visibility`. |

The issues that the raw review already marked fixed (strict parity comparisons, independent expected oracle,
raw-authority/no-op revoke checks and relation volatility) are not reopened by this follow-up.

## P4.c

| Finding | Disposition | Evidence |
|---|---|---|
| 1. Qualification result reused across read modes | Rejected again: the reviewed keys index `$active` recursion guards. Each call prepares and qualifies again; `finally` unsets the guard. A new split-PDO regression now checks both call orders and repeated switching after revoking the root role and direct permission only on primary: the stale read PDO allows, primary denies. | `Authorizer.php::isSuperAdmin`, `qualified`; `ReadSessions.php::get`; `PrimarySensitiveOperationTest.php`: `qualifies roles permissions and superadmin independently across stale read and primary PDOs` checks `isSuperAdmin`, `hasRole`, `roleNames` and `permissionSet` on the same wrappers/authorizer. `build/qualification/p4-primary-qualification-final.log`. This is PDO routing evidence, not a server replication test. |
| 2. Clean Doctor means stored morph identities migrated | Doctor's configuration check is not a stored-data migration verifier. Clarified this limit and specified the installed `TenantRef`/`AssignmentScopeRef` factories for derived keys, storage schema 2 and the distinction from JSON codec version 1. Kept the host inventory, transformation, collision and rollback duties. | `MorphIdentity.php::run`; `docs/advanced/operations.md` morph runbook; `TenantRef.php::key`, `AssignmentScopeRef.php::key`; `Sources/Database/Internal/GrantWriter.php`, `ActionWriter.php`. No schema or migration was changed. |
| 3. Doctor test morph-map leak | Rejected: the Feature/Diagnostics hook restores the morph map after each example. | `tests/Pest.php:62-66`. |
| 4. RuntimePolicy callback leak | Rejected as an inter-test contamination claim: Feature/Authorization resets the callback to null before each example. It does not rely on dataset order. | `tests/Pest.php:68-83`. |
| 5. Non-EvaluationFrame drops primary override | Unsupported extension/future-path hypothesis. Current engine requests propagate their primary flag through `EvaluationFrame`, whose effective read mode is used by DatabaseSource. The `EvaluationContext` interface does not carry a per-request primary override for arbitrary host implementations. | `DatabaseSource.php::openReadSession`, `readContributions`, `contextsCovering`; `AccessRequest.php::onPrimary`; `ReadSessions.php::get`. No public interface change was made. |
| 6. Replica listener observes write PDO, not selected PDO | Rejected for current authority sessions: `StorageReadSession` chooses the requested handle, clones the connection, and assigns that same PDO to both write and read slots. `QueryExecuted::connection->getPdo()` on that clone therefore observes the actual pinned handle. The single-database budget test remains narrower evidence; the new split-PDO qualification test also asserts different decisions against genuinely different row sets. | `StorageReadSession.php:54-79`; `tests/Engines/ReplicaLagTest.php`; `tests/Feature/Authorization/Batch/PrimaryReadModeTest.php`; `PrimarySensitiveOperationTest.php` split-PDO regression. Real paused-replay engine tests were not run here. |

## P4.gap

| Finding | Disposition | Evidence |
|---|---|---|
| 1. Gate must differ between inherit/isolated | Rejected: a native policy allow is authority in the requested scope, not an inherited grant. Both modes may allow; forcing different results would assert the wrong contract. | `GateSourceContractTest.php::exerciseTenantContext`; policy allow/deny controls; SEC-07/D10 does not redefine grant inheritance as policy inheritance. |
| 2. Gate membership revoke only checks scoped requests | Confirmed test gap, fixed. Tenant-only scalar and batch deny assertions now accompany the existing scoped denials after positive controls. | `GateSourceContractTest.php::exerciseTenantContext`. |
| 3. Filament sibling-scope denial masked by tenant-wide grant | Confirmed test gap, fixed. Revoke the tenant-wide grant before granting project 7; project 8 and tenant 8 must deny in scalar/batch while project 7 allows in both modes. | `FilamentSourceContractTest.php::exerciseFilamentTenantContext`. |
| 4. Filament post-membership-revoke combinations missing | Confirmed test gap, fixed. Restore a tenant-wide grant so both tenant-only and concrete project contexts have positive controls, then check scalar and batch denial for both after membership removal. | `FilamentSourceContractTest.php::exerciseFilamentTenantContext`. |
| 5. Folder subject-2 visibility and lifecycle gap | Confirmed missing parity controls, fixed: separate dataset examples per role adapter, finite resource universe, explicit scope and tenant, batch-first resource checks, mode-sensitive visibility and post-revoke denial. No cross-example contamination had been demonstrated; separation improves isolation. | `QueryParityContractTest.php::exerciseFolderTenantMode` and both named mode scenarios. |
| 6. Automatic-role fixture scalar/exact mismatch | Not a demonstrated runtime defect: exact selection supplies candidate contribution scopes, including tenant-wide contributions; the engine applies inherit/isolated semantics. Made subject 2 explicitly tenant A in both hooks and exercised exact visibility in both tenants/modes, including neighboring project resources. | `ContractQueryableAutomaticRole::appliesTo`, `exactAutomaticScopes`; queryable/unqueryable isolated datasets in `QueryParityContractTest.php`. |
| 7. Relation mode check actually exercises database inheritance | Correct description of the composed source test, not evidence of broken RelationSource inheritance. Added relation-only B membership and detachment parity controls in both modes, independent of the A database grant. | `ScopedSourceWorld.php::relations`; `RelationTenantContractTest.php`. |
| 8. Named coverage guard cannot prove semantics | Accepted limitation: declarations and subprocess execution detect missing/skipped/failing scenarios, not arbitrary semantic weakness. Kept that guard and strengthened the actual test bodies instead of adding a PHP source-pattern heuristic. | `CapabilityMatrixCoverageTest.php::builtInTenantModeCoverageErrors`, `builtInCapabilityExecutionErrors`; negative missing/replaced scenario test. |

The gap review's uncertainties are resolved locally: `AccessRequest::on()` retains the tenant; resource mapping
resolves the concrete context; `VisibilityWorld::compile()` replaces registry/authorizer/visibility instances;
`VisibilityWorld::reset()` clears morph state and time; relation schema setup exists; raw grant insertion uses
the existing storage fixture; `SubjectAccess::grantPermission()` accepts the assignment scope as argument two.
These observations do not extend the patch-only Grok review to unrelated files or phases.

## Recheck implementation details and limits

The original P4 run reported `coverage.status = not_reviewed` because maind and the hook blocked repository/git
reads. Its empty findings array is not a passing review. P4.a/b/c remain static patch reviews, including P4.b's
unread middle and P4.c's missing surrounding code. This execution reads their findings and the nearest current
definitions/callers, not other phases or plan state, and does not retroactively expand Grok's coverage.

The three cursor replacements use the existing framework `each()`/`chunk()` primitive, not a copied traversal
implementation or a new dependency. Each page contains at most 100 records. Export scans clone the builder so
pagination cannot alter the query later serialized by Filament. The serialized query's limit/offset is covered by
positive and empty-window preparation tests. The lower supported Laravel 12.0.0 implementation of
[`each()` and `chunk()`](https://github.com/laravel/framework/blob/v12.0.0/src/Illuminate/Database/Concerns/BuildsQueries.php)
also preserves limit/offset; its older `lazy()` does not, so this fix deliberately uses `each()`.

Focused static analysis exposed an existing mismatch at `ModelKey::where($query, $keys)` in preparation:
`array_filter` retained integer values and sparse indexes while that helper requires a list of canonical string
IDs. Preparation now validates and normalizes to that list before the lookup, retaining the positive integer-ID
case and the hidden-ID negative control. The new early return also made the old final `return false` unreachable;
it was removed. No exported API, storage schema or host-controller contract changed.

A diagnostic preflight-window assertion initially used `ExportAction::modifyQueryUsing()`. That host callback
runs later, inside Filament's action closure, after `ActionCalling`; it cannot define the earlier table preflight's
window. The assertion was removed, and the actual serialized preparation window is tested instead. A host callback
may narrow the later export query to no rows; checked preparation still refuses headers/chunks if that final query
has no authorized row. No host callback is replayed before the action, and no data-exposure claim follows from
an export entry being created before queued preparation refuses it.

## Previous execution evidence

Retained command output is under `build/qualification/p4-grok/`. `contracts-final.log` covers the four affected
source contract files. `features-final.log` covers the affected assignment, export and bulk test files;
the final bulk/export run after selection-key normalization is in `filament-final.log`.

A diagnostic PHPStan invocation including test files outside `phpstan.neon`'s configured paths failed with
149 errors, including fixture discovery/type errors; its output is retained in `phpstan.log`, not counted as
a passing gate. Runtime-only analysis is recorded separately in `phpstan-runtime.log`. No acceptance suite,
real replica, MySQL unbuffered-cursor qualification, or concurrency/atomicity proof was claimed by that execution.

## Current execution verification

Every result below is an actual invocation, not the coordinator's V1. All final commands exited 0, with no skipped
tests. The two export tests added after the first SQLite group were then run with their entire file; the final
MySQL run covers both changed Filament test files, including all six unbuffered scenarios. Test counts across
these commands overlap and must not be added as a unique-test total.

| Output under `build/qualification/` | Actual result |
|---|---|
| `p4-unbuffered-red.log` | Before the cursor fix: 6 tests, 3 pass / 3 fail. The authorized bulk/preflight/preparation cases fail; refusal controls pass. |
| `p4-unbuffered-final.log` | After the fix: 6/6 pass, 12 assertions, including denial on bulk page two. |
| `p4-focused-final.log` | SQLite: 151/151 pass, 1,079 assertions across the four source-contract files and five affected assignment/Filament files. |
| `p4-primary-qualification-final.log` | SQLite: 5/5 pass, 46 assertions, including two split-PDO qualification orders. |
| `p4-export-window-final.log` | Final export file on SQLite: 24/24 pass, 44 assertions, including both serialized-window controls. |
| `p4-mysql-files-final.log` | Both complete Filament files on disposable MySQL 8.4.13: 47/47 pass, 91 assertions. |
| `p4-diagnostics-final.log` | Morph/Doctor/telemetry/mixed-read files: 19/19 pass, 78 assertions. |
| `p4-matrix-negative-final.log` | Missing/replaced tenant-mode scenario guard: 1/1 pass, 24 assertions. The subprocess matrix suite was not run. |
| `p4-runtime-analysis-final.log` | PHPStan over the three changed runtime files: 0 errors, exit 0. Startup warns that the optional turbo extension cannot load; the analyzer completes. |
| `p4-style-final.log` | Pint over all six changed PHP files: pass, exit 0. |
| `p4-diff-check.log` | `git diff --check`: exit 0, no output. |

Exact final proof commands (output redirected to the corresponding files above):

```bash
php -d memory_limit=1G vendor/bin/pest tests/Contracts/Sources/QueryParityContractTest.php tests/Contracts/Sources/RelationTenantContractTest.php tests/Contracts/Sources/GateSourceContractTest.php tests/Contracts/Sources/FilamentSourceContractTest.php tests/Feature/Changes/AuthorizedAssignmentsExampleTest.php tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php tests/Feature/Filament/Gate/StaleBulkAuthorizationTest.php tests/Feature/Filament/Gate/SurfacesTest.php tests/Feature/Filament/Gate/ForgedCallsTest.php --exclude-group=engines --fail-on-skipped
php -d memory_limit=1G vendor/bin/pest tests/Feature/Authorization/PrimarySensitiveOperationTest.php --fail-on-skipped
php -d memory_limit=1G vendor/bin/pest tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php --exclude-group=engines --fail-on-skipped
DB_CONNECTION=mysql MYSQL_PORT=33342 MYSQL_DATABASE=azguard_p4_test php -d memory_limit=1G vendor/bin/pest tests/Feature/Filament/Gate/StaleBulkAuthorizationTest.php tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php --fail-on-skipped
php -d memory_limit=1G vendor/bin/pest tests/Feature/Diagnostics/MorphIdentityTest.php tests/Feature/Diagnostics/DoctorCommandTest.php tests/Feature/Authorization/FailureTelemetryTest.php tests/Feature/Authorization/Batch/PrimaryReadModeTest.php --fail-on-skipped
php -d memory_limit=1G vendor/bin/pest tests/Contracts/Sources/CapabilityMatrixCoverageTest.php --filter='fails the tenant mode guard' --fail-on-skipped
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G packages/filament/src/Internal/Authorization/AuthorizesActions.php packages/filament/src/Internal/Exports/AuthorizesExports.php packages/filament/src/Internal/Exports/ChecksPrepareCsvExport.php
vendor/bin/pint --test packages/filament/src/Internal/Authorization/AuthorizesActions.php packages/filament/src/Internal/Exports/AuthorizesExports.php packages/filament/src/Internal/Exports/ChecksPrepareCsvExport.php tests/Feature/Authorization/PrimarySensitiveOperationTest.php tests/Feature/Filament/Gate/StaleBulkAuthorizationTest.php tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php
git diff --check
```

Diagnostic outputs are also retained: `p4-primary-test-diagnostic.log` and `p4-primary-count-diagnostic.log`
record corrected test-authoring assumptions (`roleNames()` returns a Collection; root expands to two grant
permissions); `p4-runtime-analysis-diagnostic.log` records the key-list mismatch and newly unreachable return;
`p4-export-window-diagnostic.log` and `p4-mysql-window-diagnostic.log` record the removed late-callback assertion.
`p4-unbuffered-lazy-diagnostic.log` is an intermediate passing run before checking the lower framework version.
None of these intermediate results is represented as a final passing gate.

V1 and the full suites it may start were not run, as instructed. There is still no claim of complete Grok P4
coverage, real paused-replay replication qualification, MariaDB-specific execution, or atomic authorization with
concurrent host changes. P5 and other phases were not started; no plan files or git commits were written.

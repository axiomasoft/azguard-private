# P4 finding dispositions

Checked against HEAD `c16fdefa` and the local changes accompanying this document. Finding numbers below are
the order in each raw result's `findings` array, not a new severity ranking. Inputs are the raw `P4*.jsonl`
results and their preliminary triage under the audit's `artifacts/review/grok-phases` directory.

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
| 7. Nested queries with an unbuffered MySQL cursor | Conditional availability hypothesis, not reproduced and not called an authorization vulnerability. SQL errors in export/preparation/gate checks deny. The raw review did not establish an unbuffered production connection or a cursor silently losing rows. Server/unbuffered qualification remains outside this proof. | `AuthorizesActions.php::__invoke`, `AuthorizesExports.php::__invoke`, `ChecksPrepareCsvExport.php::hasAuthorizedRecord`, `FilamentGate.php::resource`. |

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
| 1. Qualification result reused across read modes | Rejected: the reviewed keys index `$active` recursion guards. Each call prepares and qualifies again; `finally` unsets the guard. They are not result memo keys, so adding read mode there would not repair a demonstrated stale result. | `packages/core/src/Authorization/Internal/Authorizer.php::isSuperAdmin`, `qualified`; `ReadSessions.php::get` includes effective reads in session identity. |
| 2. Clean Doctor means stored morph identities migrated | Doctor's configuration check is not a stored-data migration verifier. Clarified this limit and specified the installed `TenantRef`/`AssignmentScopeRef` factories for derived keys, storage schema 2 and the distinction from JSON codec version 1. Kept the host inventory, transformation, collision and rollback duties. | `MorphIdentity.php::run`; `docs/advanced/operations.md` morph runbook; `TenantRef.php::key`, `AssignmentScopeRef.php::key`; `Sources/Database/Internal/GrantWriter.php`, `ActionWriter.php`. No schema or migration was changed. |
| 3. Doctor test morph-map leak | Rejected: the Feature/Diagnostics hook restores the morph map after each example. | `tests/Pest.php:62-66`. |
| 4. RuntimePolicy callback leak | Rejected as an inter-test contamination claim: Feature/Authorization resets the callback to null before each example. It does not rely on dataset order. | `tests/Pest.php:68-83`. |
| 5. Non-EvaluationFrame drops primary override | Unsupported extension/future-path hypothesis. Current engine requests propagate their primary flag through `EvaluationFrame`, whose effective read mode is used by DatabaseSource. The `EvaluationContext` interface does not carry a per-request primary override for arbitrary host implementations. | `DatabaseSource.php::openReadSession`, `readContributions`, `contextsCovering`; `AccessRequest.php::onPrimary`; `ReadSessions.php::get`. No public interface change was made. |
| 6. Replica listener observes write PDO, not selected PDO | Rejected for current authority sessions: `StorageReadSession` chooses the requested handle, clones the connection, and assigns that same PDO to both write and read slots. `QueryExecuted::connection->getPdo()` on that clone therefore observes the actual pinned handle. The single-database budget test is narrower evidence and is not presented as replica qualification. | `StorageReadSession.php:54-79`; `tests/Engines/ReplicaLagTest.php`; `tests/Feature/Authorization/Batch/PrimaryReadModeTest.php`. Real paused-replay engine tests were not run here. |

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

## Focused verification

Retained command output is under `build/qualification/p4-grok/`. `contracts-final.log` covers the four affected
source contract files. `features-final.log` covers the affected assignment, export and bulk test files;
the final bulk/export run after selection-key normalization is in `filament-final.log`.

A diagnostic PHPStan invocation including test files outside `phpstan.neon`'s configured paths failed with
149 errors, including fixture discovery/type errors; its output is retained in `phpstan.log`, not counted as
a passing gate. Runtime-only analysis is recorded separately in `phpstan-runtime.log`. No acceptance suite,
real replica, MySQL unbuffered-cursor qualification, or concurrency/atomicity proof is claimed here.

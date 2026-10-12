Verdict: GREEN

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | none | — |

## Coverage

| Point | Result | What was checked |
| --- | --- | --- |
| 1 | Holds | `AssignmentScopeEvaluator` and `QueryableAssignmentScopeEvaluator` match knowledge/contracts#scopes: `Spi` Implement since `1.0`, parameter names, and the `Builder<Model>` phpdoc. `AssignmentScopeDeclaration::$evaluator` is documented `class-string<AssignmentScopeEvaluator>\|null`. `PanelCompiler::scopeEvaluator()` rejects a class with `is_subclass_of`. |
| 2 | Holds | Scalar: `BoundaryStage::structural()` does alias, `resolve()`, ref, owner tenant, then record model and key, then `eligible()`. Write: `ChangeValidator::eligibility()` does the same, then `allows(Assignment)`. Contribution uses the scope `structural()` already stored on the frame. `BoundaryStage::eligible()` returns true when the evaluator is null, so a true answer is never consulted before those checks and cannot override them. `BatchInputs::allows()` may include a sibling that has passed only ref and tenant; that sibling's decision still hits its own record check before the cached answer is read. The ref/tenant re-resolve in `AuthorityStage::contributionEligible()` runs only when `assignmentScope` is null, and a successful `structural()` always sets it before authority. |
| 3 | Holds for unlogged inputs | Access with role and grant null is definition filters plus the adapter. A contribution is role-binding filters plus the adapter; a direct grant is the adapter only. The common check is repeated only when the boundary did not store a resolved scope. Assignment is membership, then common filters and adapter, then binding filters and adapter. Revocation, touch, and migration return in `ChangeValidator::finalize()` before `eligibility()`. Logged differences stay logged: filter trace component is the evaluator class, write record mismatch is rejected, policy can deny before scope membership, and admission no longer checks scope membership. |
| 4 | Holds | `BatchInputs::allows()` calls `allowsMany()` once per kind (common versus contribution). A different answer count stores one `RuntimeException` on every key of that call. A throw falls back to `allows()` per key. `ScopeEligibility::allowsMany()` throws a per-witness `Throwable` from `EligibilityBuilder::matchesMany()` before combining it as a boolean. `eligibilityKey()` is panel, subject, actor, scope, user and actor-model identity, role key, and contribution parts; the two kinds differ by role and grant. |
| 5 | Holds | Tenant membership remains in `AuthorityStage::decide()`, `Authorizer::admissionScope()`, and `Visibility::build()`. `ScopesPlugin` adds `ScopeMembershipRestriction` only when a membership is set. Its list predicate returns pass when the mode is `None`, which is the only case where `Visibility::mapping()` returns null rather than a mapping or a throw. Write membership is `ScopeEligibility::member()` in the Assignment phase, before filters. No remaining `isMember` call checks an assignment scope outside those two paths. |
| 6 | Holds | `TenantMembershipConfigured` key `membership.configured`. `ScopeMembershipConfigured` key `scopes.membership`, finding id `membership.configured`, and `Doctor::run()` invokes it with `forPanel()`. `doctor-checks.json` records both classes. `doctor.json` attributes the inherit warnings to `plugin:azguard/scopes` with the panel in details. |
| 7 | Holds | The five module types sit at their D17 interim paths under `src/Scopes/Internal`. Core does not import `ScopeEvaluator`, `ScopeEligibility`, or `ScopeMembershipRestriction`. The i2 and i4 diffs only delete lines (84 removed, 0 added). New hot-path SPI calls are positional. `ScopeConfiguration` stays a split-owner reader for the compiler, fingerprint, and schema. That is a sound P3 interim: the hot path no longer depends on it, and P3 can separate directory, merge, and `sameStructure` from membership and adapters. |
| 8 | Holds | The trait's four tests are non-vacuous. Order and length fail on `ReversingEvaluator` and `ShortEvaluator` (`ContractStoreScope` stores 1 and 2, and their `allows()` answers differ). `ThrowingEvaluator` fails `evaluatorDoesNotFailOnOrdinaryRequests` because the engine denies with `assignment_scope_filter_error`. Narrowing asserts a grant-less decision is not allowed. The verified-scope test asserts an undeclared type is not asked. Both `ScopeEvaluatorContractTest` and `RecordingEvaluatorContractTest` use the trait. `NegativeFixturesTest` runs those three broken evaluators through `EvaluatorProbe`. |
| 9 | Holds | `PanelCompiler::scopes()` and `RoleCompiler::scopes()` throw `DefinitionException` when the mode is not `None`, filters are present, and the evaluator is null. `scopeDirectories()` takes the directory from the merged definition and rejects a different mapped class (`ScopeDeclarationTest`). |
| 10 | Holds | `ExplainTest` still requires a filter deny and one adapter observation; the component is now `ScopeEvaluator`. `AssignmentRuntimeTest` keeps active versus inactive filter results and moves missing and foreign-scope refusal onto the write, asserting the evaluator is not asked. `PanelChecksTest` still requires the inherit warning, now on the plugin, and expects no `membership.configured` finding from a core-slot declaration. `docs/upgrade.md` has the evaluator, `scopes.membership`, and membership-stage lines. |

## Forward risks

P2.9: `QueryScopeDirectory` still runs `EligibilityBuilder`, `RoleBindings`, and `AssignmentScopeAccessAdapter::allowsMany()` on its own. A custom evaluator is not on that path until the directory calls `allows(Assignment)`. A directory class coming from the merged definition is stored without the `is_subclass_of(AssignmentScopeDirectory)` check that a mapped directory gets. `DirectoryResolver::scopes()` throws `DefinitionException` when the object is not a directory.

P2.10: `VisibilityScope` still builds the eligibility SQL itself. A custom evaluator is not consulted, and a declaration whose evaluator does not implement `QueryableAssignmentScopeEvaluator` does not raise `VisibilityNotSupportedException` `scope_query`. `ScopeEvaluator` implements only the base interface, which this item defers. The queryable interface is already the method P2.10 has to fill.

P3: moving all of `ScopeConfiguration` into the module without splitting it will put the compiler, fingerprint, schema, visibility, and directory reads back on the I2 list.

## Commands and observations

- `git show --stat eb3de8f0` and `git diff 748f1f52 eb3de8f0`: 77 files, the hot path and the membership split described above. `tests/Arch/Fixtures/target-map.json` is not in the diff. `views/` was not read.
- Plan API: `get PLAN1 P2.8`, contracts#scopes, architecture#scope-boundary, architecture#operation-chains, p2-execution, type-map zone-authorization, zone-diagnostics, zone-new, identities#doctor, D7, D8, D16, D17, `events PLAN1 --item P2.8` and `--item P2.7`.
- `php8.4 -l` on the evaluator interfaces, `ScopeEvaluator`, `ScopeEligibility`, `ScopeMembershipRestriction`, `BatchInputs`, both stages, `ChangeValidator`, `PanelCompiler`, and the contract trait: no syntax errors.
- `php8.4 bin/api-manifest.php --check`: exit 0.
- i2.json and i4.json against `748f1f52`: 0 added lines, 84 removed.

## Limits

Pest, the SQLite suite, and the driver and engine suites were not run here; another process is using this worktree for those. Executor results V1 736/736, V2 159/159, and V3 42/42 were not re-executed. The conclusions above are from the commit diff and the current sources.

Model: Grok 4.7.
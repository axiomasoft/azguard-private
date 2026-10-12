Verdict: GREEN

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | none | — |

## Coverage

| # | Verdict | What was checked |
| --- | --- | --- |
| 1 | Pass | `DirectoryResolver::scopes()` reads `Panel::scopeDeclaration()->directories[$type]` and `container->make`s it; a missing class returns an anonymous empty `AssignmentScopeDirectory` (`search` `[]`, `describe` `null`). No reference to `QueryScopeDirectory` or `ConfigurableAssignmentScopeDefinition` remains in that class, and `tests/Arch/Allowlists/i2.json` has no `DirectoryResolver` rows. `SchemaBuilder` reports `scopeDirectory($type)` or, if none, `AssignmentScopeDirectory::class`. That sentinel is not instantiable and is not the anonymous runtime class. Nothing in core constructs the schema string, and a type with no directory is specified to offer nothing, so the contract name is an acceptable label. |
| 2 | Pass | `ScopesPlugin::register()` maps `QueryScopeDirectory` only for `QueryableAssignmentScopeDefinition`. `PanelCompiler::scopeDirectories()` still rejects two different mapped classes, then lets `ScopeConfiguration::directory()` overwrite the map. That matches 0.7.0 `DirectoryResolver::scopes()` (definition directory, else `QueryScopeDirectory`). Runtime class is unchanged for plugin queryable types and for `->directory()`. The only changes are the logged ones: a non-queryable type, and a type added only by a core `assignmentScopes()` with no directory, now resolve to the empty directory instead of `QueryScopeDirectory`. A second declaration map that disagrees with the plugin default is a `DefinitionException` (recorded assumption). |
| 3 | Pass | `admitTenancy()` / `admitContext()` match `ChangeValidator::tenancy()` / `eligibility()` at `eb3de8f0`: same messages, exception classes, `AzGuardException\|PDOException` rethrow, and other `Throwable` wrapped as `AssignmentScopeNotAcceptedException`. `finalize()` already rejects a null subject before `eligibility()`, so moving that check above `admitContext()` is not reachable on the write. `??` on a null `scopeDeclaration()` evaluates to `None` with no warning. |
| 4 | Pass | Assignment `search()` runs tenant mode, target membership, scope mode (`global` forced false, because every offered context is non-global), declared type, catalog role, and role-type once per lookup. `describe()` adds `admitContext()`. Other phases return the inner directory unchanged. Global role on a required-tenant panel uses the same `globalRoles()` class check as the write. No target, unknown catalog role, and a role not granted in the type yield no option; the write also refuses those grants. A non-`AzGuardException` from `admitTenancy()` propagates, as on the write. One from `admitContext()` is wrapped, then `describe()` turns that refusal into `null`. A custom directory's `search()` is not filtered per candidate (`ProjectDirectory` can still list seller-ineligible projects); `describe()` does revalidate. That matches the recorded split: per-candidate checks stay in `QueryScopeDirectory`, and `admitContext()` per option would be the N+1 the item forbids. |
| 5 | Pass | Assignment `QueryScopeDirectory::members()` calls the same `ScopeConfiguration::membership()` / `isMember($subject, ref)` predicate as `ScopeEligibility::assignment()`, using `AssignmentScopeRef::of($type, ModelKey::of($record))`, which is the ref `resolve()` returns for a model scope. `WriteEqualDirectoryTest` asserts the member set and that query count does not grow between limits 1 and 10. That comparison shares one chunk and the default panel has no scope membership, so a querying `isMember` would still be one query per candidate in the chunk. There is no batch membership API. |
| 6 | Pass | `i9-findings-0.7.0.json` at `eb3de8f0` had 82 assignment rows (F1 28, F2 54), each `directory=allow` and `write=deny`. It is now `findings: {}` and `disagreements.assignments: []`. `unsupported-0.7.0.json` is unchanged. No authorization code is in the diff, so scalar/batch/SQL are untouched. `ParityMatrixTest` still requires zero unlisted disagreements. F1 and F2 are named tests in `WriteEqualDirectoryTest` and assert an empty offer where the old directory returned rows. |
| 7 | Pass | Filament R37 now expects the form error for a project in a tenant the target is not a member of; the write throw remains the F2 test. R50, `QueryScopeDirectoryTest`, `PanelDirectoriesTest`, and `ScopeDeclarationTest` assert the new directory rule rather than a weaker one. `ChangeRaceTest` uses `ChangePipe`'s `(Change, Closure): ChangeResult` shape; the fingerprint still records `closure`. `php -l` warns that `use Closure;` has no effect in that un-namespaced file (E_WARNING on include). PHPUnit enables its warning handler per test, after the file is loaded, so this is not a confirmed suite failure. The two `docs/upgrade.md` lines are in `## From 0.7 to 1.0` as `Find` / `Replace`. |
| 8 | Pass | `ZonesArchTest` forbids `Directories` → `Storage`, `Changes`, and `Authorization`. `DirectoryResolver` imports none of those. The zone blocks the other placement, and the item allows either `Changes` or `Directories` internals, so `admitTenancy()` / `admitContext()` on the internal resolver, called from `ChangeValidator`, is the placement that type-checks. |

## Forward risks for P2.10

Visibility must keep using the declaration and the evaluator, not `PanelDirectories` search results. Assignment search is narrowed; Inspection search is not, and an alias with no directory is empty while the write still accepts a submitted id. Scope-membership SQL has to stay equal to `isMember`, which the directory now applies per candidate. An empty directory must not be read as "this alias is undeclared."

## Commands and observations

- `git rev-parse` / `git show --stat 58e3cf56` / `git diff --stat eb3de8f0 58e3cf56`: HEAD is `58e3cf56`, 19 files, +420/−562.
- `plan.py get` for P2.9, `knowledge/p2-execution`, architecture `operation-chains` and `scope-boundary`, contracts `scopes`, D7, D16, D17; `events` for P2.9, P2.8, P2.7.
- Compared `v0.7.0` and `eb3de8f0` `DirectoryResolver::scopes()` and `eb3de8f0` `ChangeValidator::tenancy()` / `eligibility()` with the new methods.
- `php8.4` probe: `$panel->scopeDeclaration()->mode ?? 'none'` on a null declaration returns `none` and does not warn.
- `php8.4 -l` on the eight changed PHP files: no syntax errors. `ChangeRaceTest.php` prints `The use statement with non-compound name 'Closure' has no effect`.
- `i2.json` has no `DirectoryResolver` or `QueryScopeDirectory` rows. `git diff --exit-code` on `unsupported-0.7.0.json` is empty. `php8.4 bin/api-manifest.php --check` exited 0.
- Python count of the old findings file: 82 assignment disagreements, F1 28 and F2 54.

## Material limits

Pest was not run. Another process is running the full suite in this worktree, and driver and engine suites were out of scope. V1–V4 were not re-executed here. The allow/deny bits of the parity matrix were not recomputed; the authorization diff is empty and `api-manifest --check` was run in this worktree. Custom-directory search versus a live grant was traced from `DirectoriesTest` R36/R50 and `PanelDirectories`, not executed.

Model: Grok 4.7.
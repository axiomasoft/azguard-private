Verdict: GREEN

## Findings

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | none | — |

## Coverage

| check | result | evidence |
| --- | --- | --- |
| (a) Item scope and acceptance | Met on the tree at `3f1efb46` | `AuthorityStage` has no `instanceof` of `FolderSource`, `DatabaseSource`, `RelationSource`, or `GateSource`. Admission is `instanceof EvaluatesAutomaticRoles` at `packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php:221`. `bindRoleClasses` runs once in `PanelSources::of` when a catalog is passed (`packages/core/src/Sources/PanelSources.php:88-89`), from `PrepareStage.php:168`, `Visibility.php:97`, and the `AuthorityStage` fallback at line 179. A repo search shows no remaining `bindRoleClasses` call in `AuthorityStage` or `ReadAttempt`. The marker interface has zero methods, is `@internal`, and is implemented by `FolderSource`. Oracle row is `core` / `internal` / `new` / `P3` in both `target-map.json` copies; `tests/Arch/Fixtures/target-map.sha256` is `6341f787fe2195903af4117e8273384c12d6ba8aa901a171ca6b5da3adf38777` and matches the file. The amendments table lists the row as added by P3.3. `ReadAttempt::readSource` is the parent `AuthorityStage::readSource` body (whitespace-normalized equality). `ReadAttempt` still branches on `DatabaseSource` and `FolderSource`. |
| (b) Behavior, order, errors, API/SPI | No change found beyond the requested move | The relocated body keeps `readContributions`, the contribution trace, `withState`, then the three-attempt `FencesReads` loop, type checks, and `ConsistencyException` text. In `contributions` and `selections`, the only edit is deletion of the `FolderSource::bindRoleClasses` block that sat after `begin()` and before the existing `DatabaseSource` branch. `begin`, `databaseItems`, `fenced`, `observe`, and `close` are outside the hunks. `PanelSources` and `ReadAttempt` are absent from `packages/core/api-manifest.json`. `FolderSource` interface names from reflection match that manifest, whose bytes are unchanged versus `3f1efb46^`. `docs/upgrade.md` is unchanged. |
| (c) P3 conventions and I21/I22 | Holds | No `class_alias`, forwarding type, or deprecated member in the product diff. The new file is `packages/core/src/Sources/Internal/EvaluatesAutomaticRoles.php`, the oracle `target_path`. Marker is the `@internal` docblock used by other internal types. No allowlist entry and no snapshot fixture is in the diff. I21/I22 call order on the database path is the previous order: snapshot read, session grouping, fenced re-read, dirty-read `observe`, and handle release stay in `ReadAttempt` methods this commit does not reorder. The removed `bindRoleClasses` only assigns `FolderSource::$roleClasses`. |
| (d) Tests for the changed behavior | Arch ban is present and non-vacuous | `tests/Arch/AuthorizationArchTest.php:78-100` scans `Pipeline/Stages`, `Query`, `Internal/Query`, and `Visibility.php`, requires `ReadAttempt.php` to still contain concrete checks, and asserts a scratch file. Replaying that scanner: parent `AuthorityStage` yields `AuthorityStage.parent.php:185 FolderSource`, `:228 FolderSource`, `:314 DatabaseSource`; HEAD stage/query/visibility yields 0; HEAD `ReadAttempt.php` yields 19; the scratch sample yields exactly `InjectedStage.php:4 AzGuard\Sources\Folder\FolderSource` and `InjectedStage.php:4 AzGuard\Sources\Database\DatabaseSource`. `tests/` is not in the commit, so existing admission assertions are unmodified. |

## Commands run

- `git rev-parse HEAD` → `3f1efb4643c60e73e7370b5a2d5f8208689b512b`. `git status --short` → untracked `vendor` only.
- `git show --stat 3f1efb46` and `git show 3f1efb46 -- packages docs tests` → seven product files (`AuthorityStage.php`, `PrepareStage.php`, `ReadAttempt.php`, `Visibility.php`, `FolderSource.php`, `EvaluatesAutomaticRoles.php`, `PanelSources.php`). No `docs/` or `tests/` hunks.
- `plan.py get` for `P3.3`, `knowledge/p3-execution`, `knowledge/architecture` sections `invariants` and `operation-chains`, `D5`, and `D10`.
- `php8.4 -l` on the seven product files → no syntax errors.
- Python compare of parent `AuthorityStage::readSource` with `ReadAttempt::readSource` → bodies equal after whitespace normalization. The rest of `ReadAttempt` differs by the inserted method and the two deleted `bindRoleClasses` blocks.
- `php8.4` replay of `concreteSourceChecks` from `AuthorizationArchTest.php` → parent hits 3, HEAD decision-stage hits 0, `ReadAttempt` hits 19, RED sample matches the test’s expected list.
- `php8.4` reflection of `FolderSource::getInterfaceNames()` sorted with `SORT_STRING` → equal to the `FolderSource` `interfaces` array in `packages/core/api-manifest.json`. `EvaluatesAutomaticRoles` has no manifest entry of its own and `getMethods()` count 0.
- `sha256sum tests/Arch/Fixtures/target-map.json` matches `target-map.sha256`. `cmp` of that file with `plans/.../artifacts/inventory/target-map.json` → identical.
- `php8.4 -d memory_limit=1G vendor/bin/pest tests/Arch/AuthorizationArchTest.php --filter concrete-source` → exit 255 before any assertion. Pest builds a namespace from this worktree path; the segment `2be1da6c…` is not a legal PHP identifier (`ParseError` in `pestphp/pest/src/Factories/TestCaseFactory.php:179`).

## Material limits

Full suites, `tests/Arch` as a directory, composer scripts, phpstan, rector, pint, docker, and database driver or engine suites were not run. `DecisionSetSnapshotTest` and `ReadSessionLifecycleTest` were not executed; I21/I22 order was checked from the diff and the byte-level body compare. Pest cannot load tests from this worktree path, so the arch assertions were executed by running the test’s scanner directly.

Model: Grok 4.7.
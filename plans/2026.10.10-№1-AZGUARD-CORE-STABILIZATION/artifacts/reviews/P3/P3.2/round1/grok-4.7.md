Verdict: GREEN

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| (none) | | | | | |

## Coverage

| Check | Result |
| --- | --- |
| (a) Item scope / acceptance | Met at `0a36bbed`. The product diff is only `packages/core/src/Directories/QueryScopeDirectory.php` (+84/−3). `ModelLookup::match`, `label`, and `searchColumns` are private copies with the same control flow, bindings, escape (`!`, `%`, `_`), integer-key rule, and `DefinitionException` text. Call sites are `search` and `describe` only (`QueryScopeDirectory.php:79`, `:118`, `:149`). `FilterQuery` / `FilterGuard`, positional `($query, $runtime)` invocation, `ScopeConfiguration::closure()`, the `docs/upgrade.md:98-101` migration line, the two oracle rows, and the removal of the `EligibilityBuilder` / `QueryScopeDirectory` `i4.json` entries are already in the parent `fddb1711` and still hold. `ResourceContextMapping.php:150-158` still passes a core `PredicateBuilder` into `eligibleContexts()`. No `ScopeLookup` class. A literal V2 grep still hits `Scopes/Query/{PredicateBuilder,PredicateQuery,QueryGuard}.php`; those are the core classes P3.4 moves, and no other file under `packages/core/src/Scopes` or `QueryScopeDirectory.php` matches. |
| (b) Behavior / API | No semantic, ordering, error, or public API change versus `0a36bbed^`. `git diff 0a36bbed^ 0a36bbed` has no manifest or `docs/` change. Public `search` / `describe` signatures are unchanged. `FilterGuard` matches `QueryGuard` (same `RuntimeException` text, sticky `rejected`). `FilterQuery` matches `PredicateBuilder` / `PredicateQuery` for `setQuery`, `newModelInstance`, `delete`, `forceDelete`, nested `where`, `has`, `shape` / `validateShape`, and terminal `runSelect` / `cursor` / `explain` / `applyBeforeQueryCallbacks` / `toSql`. `EligibilityBuilder::witness()` matches `Narrow::int(..., 'batch witness')`, including the `UnexpectedValueException` text. |
| (c) Conventions / invariants | No shim, `class_alias`, or deprecated member in the diff. New module types stay under `src/Scopes` until P3.4, with `@internal`, and the oracle `target_path` follows the same future `modules/scopes/...` pattern as `ScopeVisibility`. `tests/Arch/Fixtures/target-map.json` equals the plan artifact; both sha256 to `6341f787fe2195903af4117e8273384c12d6ba8aa901a171ca6b5da3adf38777`, matching `target-map.sha256`. This commit does not edit that JSON. |
| (d) Tests | Not vacuous. `tests/Feature/Directories/QueryScopeDirectoryTest.php:150` and `:163-169` lock key match, empty term, non-match, and a non-digit term. `tests/Feature/Scopes/NativeBuilderTest.php:107-157` locks join, order, limit, replacement, `setQuery`, `delete`, `forceDelete`, base `delete`/`update`, and a caught terminal call. `tests/Feature/Scopes/FilterConfigurationTest.php:206-229` locks the compile-time `DefinitionException` (alias `crm.project`) and the accepted untyped / short signatures. Ran those three files: 74 tests, 256 assertions, OK. |

## Commands and observations

- `plan.py get` for `P3.2`, `D22`, `D18`, `knowledge/p3-execution` (`conventions`, `module-guard`, `oracle`, `allowlists`, `amendments`), `knowledge/architecture --section invariants`, and `P3.4` scope. P3.4 owns the `PredicateBuilder` move and the PSR-4 relocation.
- `git show 0a36bbed -- packages docs tests` and `git diff --name-only 0a36bbed^ 0a36bbed`: one product file.
- `git grep -nE 'PredicateBuilder|QueryGuard|RuntimeInvoker|Narrow::|ModelLookup' 0a36bbed -- packages/core/src/Scopes packages/core/src/Directories/QueryScopeDirectory.php`: only the three core class files above. Excluding them: no matches. `i4.json` at this commit has no `EligibilityBuilder` or `QueryScopeDirectory` entries (they were present at `fddb1711^`).
- `php8.4 -r` user-function call with extra arguments: allowed (`extra-ok`), so a shorter closure passed by `ScopeConfiguration::closure()` is callable as `($query, $runtime)`.
- `php8.4 -d memory_limit=1G vendor/bin/pest tests/Feature/Directories/QueryScopeDirectoryTest.php tests/Feature/Scopes/NativeBuilderTest.php tests/Feature/Scopes/FilterConfigurationTest.php` from a private tree whose Composer autoload pointed at this worktree: `OK (74 tests, 256 assertions)`, 1.8s, PHP 8.4.26.

## Material limits

The worktree path makes Pest emit a namespace segment that starts with a digit, so Pest cannot load tests in place. Linked `vendor` autoload resolves `AzGuard\` to the main checkout’s `packages/core`, not this worktree. The green run used a throwaway copy and a pinned autoload. It did not run `tests/Arch`, `composer test`, phpstan, rector, pint, docker, or database driver suites. `git status` is unchanged except the pre-existing untracked `vendor` link.

## Model

Grok 4.7 (xAI). No finer build id was exposed in the environment.
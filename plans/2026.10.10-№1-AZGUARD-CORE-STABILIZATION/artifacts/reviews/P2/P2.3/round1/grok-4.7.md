Verdict: GREEN

## Findings

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | none | — |

## Coverage

| # | Verdict | What was checked |
| --- | --- | --- |
| 1 | Pass | `AzGuard\AzGuard` is `#[Api(since: '1.0')]`, `final`, constructor `Application $app` with `/** @internal built by the container */`. Public methods are exactly the contract set: `panel`, `panels`, `currentPanel`, `sources`, `registerPanel`, `configurePanel`, `configurePanels`, `check`, `authorize`, `withinScope`, `currentScope`, `actingAs`, `storage`, `storages`, `fake`. Parameter names, PHP types, and defaults match, including `storage(string $id = 'default')` and `check`/`authorize` subject `Model\|Authenticatable\|SubjectRef`. Documented generics are `@template TResult`, `@param Closure(): TResult $callback`, `@return TResult` on `withinScope` and `actingAs` (the contract schematic writes that binding as `T`; the rename diff left the existing `TResult` docblock). Callable and `class-string` docs match (`Closure(PanelBuilder): mixed`, `class-string<PanelProvider>`, `array<string, Panel>`, `list<StorageDescriptor>`). Diff against `HEAD:AzGuardManager.php`: `check`/`authorize`/`subjectAccess` change only the subject parameter type; those bodies are unchanged, so a non-model `Authenticatable` (`GenericUser`) still throws `SubjectNotAcceptedException`. `packages/core/src/AzGuardManager.php` is gone. No `class_alias`. `git grep AzGuardManager -- ':!plans' ':!audits' ':!tests/Arch/LegacyNamesTest.php' -- '*.php'` is empty. |
| 2 | Pass | `AzGuardServiceProvider` diff is the binding pair only: `singleton(AzGuard::class)` and `alias(AzGuard::class, 'azguard')`. Scoped bindings are untouched. Probe: both resolves are one `shared` singleton (`entry === app('azguard')`), alias abstract `AzGuard\AzGuard`. Facade `getFacadeAccessor()` is `azguard`. Instance state is still `fake` and `app` (unit test). |
| 3 | Pass | `StorageDescriptor` is `final readonly`, `#[Api(since: '1.0')]`, promoted `id`, `?string $connection`, `prefix`, `hostKeys`, constructor `/** @internal built by core */`, `canonicalKey(int\|string $key): string\|int`. It calls `HostKeyColumns::canonical` and returns that string, except `bigint`, where it casts to `int`. Probe: string `7`→`"7"`, `"007"` stays `"007"`; bigint `"42"`, `42`, `"0"`, and `PHP_INT_MAX` (`9223372036854775807`) return the same int and the same digit string; `"007"`, `"-1"`, and a 20-digit value throw `InvalidIdentityException`; uuid and lowercase ulid pass through as strings. `int` is inside the contract union and is lossless because `HostKeyColumns` already rejects values above `PHP_INT_MAX`. Probe on a booted app: configured `connection: null` stays `null` while `connectionName()` is `testbench`; configured `'audit'` stays `'audit'`; `own(null)` stays `null`; `own('audit')` stays `'audit'`; `storage('nowhere')` throws `InvalidConfigurationException`. `storages()` is a 0-based list in registration order (configured, then `own()`, then `register()`). `own()` dedup still returns the existing object and does not rewrite a stored `null`. A storage added only through `register(new Storage(..., $db->connection(), ...))` has no declared connection, so the descriptor reports `connectionName()` (`"testbench"` for the default connection). Both production writers (`config` and `own()`) record the declaration, so `null` still means “declared as the default connection.” `get`/`register`/`own` storage identity is unchanged. |
| 4 | Pass | `php8.4 bin/api-manifest.php --check` exited 0, so `packages/core/src/Facades/AzGuard.php` is byte-equal to `apiFacadeSource()`. The block has the 15 entry methods, `Closure(): mixed` / return `mixed` where the entry has `TResult`, `storage(string $id = 'default')`, `?Panel`, `?AssignmentScopeRef`, `?string`, `Model\|AssignmentScopeRef\|null`, `class-string<PanelProvider>`, `array<string, Panel>`, `list<StorageDescriptor>`, and `\AzGuard\Testing\AzGuardFake`. No `use AzGuard\Testing\…`. Union order `UnitEnum\|string` is reflection’s spelling of the declared `string\|UnitEnum`. Manifest `facade` was `[]` and now lists those 15 names; class diff is `AzGuard`, `StorageDescriptor`, `PanelOverview::__construct`’s parameter type, and the fake’s mirror methods. `--check` fails when the file differs (`$current !== $facade`). Zones rule `production code does not import test helpers` passed in the arch run below. |
| 5 | Pass | Reflection: 0 signature drifts across the 15 entry methods. `fake()` is `AzGuardFake` on the entry and `self` on the final fake; `ApiManifestTest` locks both spellings and compares every other signature (names, types, defaults). Each new method forwards to `entry()` with the same arguments. `registeredPanel()` is the old private `panel()` body (`PanelRegistry::find`); assertion call sites use it. |
| 6 | Pass | `AzGuardFacadeTest` expects `TypeError` for `check('anna')`, `check(null)`, and `authorize(42)`, and still expects `SubjectNotAcceptedException` for `GenericUser` and a `SubjectRef` the panel rejects. Canonical and unknown-id tests use strict `toBe(42)` and `toThrow(InvalidConfigurationException::class)`. `resources.json` changes only `container_alias.azguard` from `AzGuard\AzGuardManager` to `AzGuard\AzGuard` (`same_instance` stays true), matching `ResourcesSnapshotTest` (D16). `docs/upgrade.md` adds the rename bullet and the subject-union bullet under `## From 0.7 to 1.0`. |
| 7 | Pass | `Storage.php` is untouched. No `IdentityColumns`. No public method beyond the entry contract. Manifest adds no other class. |

## Forward risks

- `StorageDescriptor::canonicalKey()` returns `int` for `bigint`, while `HostKeyColumns::canonical()` always returns `string`. P2.11 code that uses `===` against the string form will not match the descriptor’s int. The digit string is the same through `PHP_INT_MAX`.
- A storage inserted only with `StorageRegistry::register()` reports the resolved connection name. Configured storages and `own($connection)` keep a declared `null`. Callers that treat every default-connection descriptor as `null` will misread a directly registered one.
- Item V2 as written (`git grep AzGuardManager -- '*.php'`) still hits `plans/2026.10.01-*`, `audits/2026-09-29-audit/Research/behavior-probes.php`, and `AzGuardManagerInterface` in `tests/Arch/LegacyNamesTest.php`. The pathspec used in the deviation event is clean.
- Non-union subjects to `check()`/`authorize()` now raise `TypeError`. A non-model `Authenticatable` still raises `SubjectNotAcceptedException`.
- `Facades\AzGuard.php` is entirely generator output, including the prose and `#[Api(since: '0.7')]`. Later entry-signature edits have to be regenerated or `api:check` fails. `AzGuardFake::panel()` now returns `PanelAccess`; the spy lookup is `registeredPanel()`.

## Commands and observations

- `git status --short`, `git diff HEAD --stat`, `git rev-parse HEAD` → `9df9b57dea920c0bb03443d89299ea7f4a2a6549`; staged rename `AzGuardManager.php` → `AzGuard.php`; untracked `StorageDescriptor.php`.
- `plan.py get` for P2.3, `knowledge/contracts` entry and storage, `knowledge/p2-execution`, `knowledge/architecture` api-compatibility, `knowledge/target-structure` rules, `knowledge/contracts` rules, D4, D9, D16, `events --item P2.3`, `knowledge/type-map` zone-root.
- `diff -u` of `HEAD:AzGuardManager.php` against `AzGuard.php`; semantic JSON diff of `packages/core/api-manifest.json` (added `AzGuard`, `StorageDescriptor`; changed `PanelOverview::__construct` and `AzGuardFake`).
- `php8.4 -l` on the touched PHP files → no syntax errors.
- `php8.4 bin/api-manifest.php --check` → exit 0.
- `/tmp` reflection probe → signatures, generics, canonical edges, fake drift count 0, facade lines, accessor `azguard`.
- `/tmp` Testbench probe → singleton, connection matrix, `storages()` order, unknown id.
- `php8.4 -d memory_limit=1G vendor/bin/pest tests/Arch/ApiManifestTest.php tests/Unit/Facades/AzGuardFacadeTest.php tests/Feature/Facade/AzGuardFacadeTest.php tests/Feature/Storage/StorageRegistryTest.php --fail-on-skipped` → 38 passed, 2604 assertions.
- Same pest binary on those files plus `tests/Arch/ZonesArchTest.php` with a name filter → 75 passed, 339 assertions (includes the Facades-zone testing-import rule).
- `git grep` pathspec for `AzGuardManager` → empty. `class_alias` not in the PHP diff.

## Limits

Full `composer test`, `analyse`, `lint:check`, `refactor:check`, engine suites, and docker were not re-run. Executor V5 (4914/4914) and V6 on PHP 8.4.26 were not reproduced here. `views/` was not read. `tests/Arch/Fixtures/target-map.json` is unmodified. System `php` 8.3 was not used.

Model: Grok 4.7 (xAI), as identified in the session instructions.

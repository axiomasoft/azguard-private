# Review P3.3–P3.4 — 2026-10-04

**Verdict: RED.** One blocker (R1). P3.3 has no blocker or major. P3.4 does not make `Storage::mutate()` the only Eloquent write path on the installed framework.

**Reviewer:** grok-4.7 / high. Fresh read-only pass. No source, tests, config, plan, journal, handoff, or findings were edited. No subagents. Pest and the engine databases were not run again. Owner dirt (`.gitignore`, `.swissknife.json`, `.grok/`) was ignored.

## Scope

Reviewed tree: branch `main` at `9e88024`, plus the uncommitted P3.3/P3.4 working tree (tracked diffs and untracked files under the paths below). Not a proposal and not P3.5.

| Source | What was read |
|---|---|
| Contracts | `phases/P3/P3.3.md`, `phases/P3/P3.4.md`, `decisions/D12-p3-storage-closure.md` п.1 and п.3–п.6, dossier `08` §3 and `06` §6, brief D22 / D34 / D46 / D63 |
| Product | `Storage/Models/{RoleGrant,PermissionGrant,Permission}`, `Storage/Concerns/{BelongsToStorage,GrantIdentity,GuardsDirectWrites}`, `Storage/{Storage,GrantFields,WriteGuardedBuilder}`, `Schema/{Field,FieldTarget}`, `Panels/{Panel,PanelBuilder,PanelCompiler}`, `Configuration/AzGuardConfig`, `config/azguard.php`, `Exceptions/InvalidChangeFieldsException` |
| Installed Laravel 13.33.0 | `Eloquent/Model`, `Eloquent/Builder` (`$passthru`, `__call`), `Query/Builder` write methods, `Query/Grammars/{Grammar,SQLiteGrammar,PostgresGrammar}`, `Validation` `boolean` / `required` / `Rules/Enum` |
| Tests (read, not executed) | `tests/Unit/Schema/FieldTest.php`, `tests/Feature/Storage/{GrantModels,CustomModel,GrantFields,DirectWrites}Test.php`, `tests/Feature/Panels/PanelFieldsTest.php`, `tests/Engines/{GrantFields,DirectWrites}EngineTest.php`, `tests/Fixtures/Storage/*`, `tests/Arch/{SourceScan,ZonesArchTest}.php` |

Raw SQL and a caller unwrapping the builder with `toBase()` / `getQuery()` are out of contract (D22, P3.4 Scope Excluded) and are not findings. Tenant ownership of `Field::model` and model search are P5.2 / P4.6 / P7.5 and were not required.

## Findings

| ID | Severity | Place | Violated source | Owning item |
|---|---|---|---|---|
| R1 | blocker | `packages/core/src/Storage/WriteGuardedBuilder.php:16` | P3.4 Intent and Implementation Rules; D22; D12 п.1; 08 §3 | P3.4 |

### R1 — two installed builder writes never reach the guard

`WriteGuardedBuilder` overrides the write methods named in P3.4, including `saveOrIgnore` on the model, `incrementEach`, and builder `touch`. It does not override `insertOrIgnoreReturning` or `updateFrom`. Both exist on Laravel 13.33.0. `DirectWritesTest` never calls them (`tests/Feature/Storage/DirectWritesTest.php:71-84`), so a green run does not cover this path.

**Observed**

- `insert`, `insertGetId`, `insertOrIgnore`, `insertUsing`, and `insertOrIgnoreUsing` are real methods on `WriteGuardedBuilder`, so they throw before SQL.
- `insertorignorereturning` is on `Eloquent\Builder::$passthru` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:129`). It is not overridden. `Builder::__call` (`:2335-2336`) therefore runs `toBase()->insertOrIgnoreReturning(...)`.
- `Query\Builder::insertOrIgnoreReturning` (`Query/Builder.php:4264-4298`) compiles SQL and calls `connection->selectFromWriteConnection`. `SQLiteGrammar` (`:300-307`) and `PostgresGrammar` (`:406-413`) both emit `INSERT ... ON CONFLICT DO NOTHING RETURNING ...`. Base `Grammar::compileInsertOrIgnoreReturning` (`Grammar.php:1326-1328`) throws `RuntimeException` before the connection call. MySQL and MariaDB grammars do not override it.
- `updateFrom` is not on `$passthru` and is not overridden. `Builder::__call` (`:2339`) forwards it to the inner query. `Query\Builder::updateFrom` (`:4391-4402`) calls `connection->update` when the grammar has `compileUpdateFrom`. `PostgresGrammar::compileUpdateFrom` exists (`PostgresGrammar.php:539`). Other installed grammars do not, so those engines throw `LogicException` first.
- `Model::__call` (`Model.php:2911`) forwards an unknown instance call to `newQuery()`. A bound model from `Storage::model()` therefore hits the same builder. `newEloquentBuilder` is `final` and returns `WriteGuardedBuilder` for `RoleGrant`, `PermissionGrant`, `Permission`, and subclasses such as `AdminRoleGrant`.
- `setModel` points that builder at the table set by `BelongsToStorage::bindToStorage`. For a default storage model that table is `{prefix}role_grants` / `{prefix}permission_grants` / `{prefix}permissions`.
- Nothing on this path calls `rejectDirectWrite()`. There is no environment branch.

This is not the excluded escape. The caller does not call `toBase()` or `getQuery()`. `insert` is on the same `$passthru` list and is overridden so that passthru does not run. `insertOrIgnoreReturning` is the sibling that was left open. `updateFrom` never goes through `toBase()` at all. `Model::saveOrIgnore` is overridden and does not reach `performInsertOrIgnore`, which is the framework path that uses `toBase()->insertOrIgnoreReturning`. The builder method remains public.

**Trigger**

```php
$model = $storage->model('role_grant'); // or permission_grant / permission / AdminRoleGrant
$model->newQuery()->insertOrIgnoreReturning([[/* one full grant or permission row */]]);
$model->newQuery()->updateFrom(['origin' => 'import']); // PostgreSQL
```

The same methods on the model instance (`$model->insertOrIgnoreReturning(...)`, `$model->updateFrom(...)`) dispatch through `Model::__call` to that query. `withoutEvents()` does not apply: these methods do not fire Eloquent save/delete events.

**Inferred impact (not executed here)**

- On SQLite and PostgreSQL, a row that satisfies the table constraints is inserted by `insertOrIgnoreReturning` with no `Storage::mutate()`, no panel lock, and no version bump.
- On PostgreSQL, `updateFrom` updates matching rows the same way.
- On MySQL and MariaDB the call still does not throw `UnsupportedDirectWriteException`; the grammar throws `RuntimeException` or `LogicException` before SQL.
- An unbound `RoleGrant::insertOrIgnoreReturning(...)` uses `new static` and `getTable()` (`Model.php:2343`), so the table name is `role_grants`, not `{prefix}role_grants`. That is a separate miss of the guard. The storage-table write is the bound `Storage::model()` path above.

## Checked, no blocker or major

Facts below are from the source and the tests that encode it. They were not re-executed.

**P3.3 models.** Base classes are concrete. `Storage::model()` requires the matching base class, rejects an abstract class, and compares a declared `$table` / `$connection` from `ReflectionClass::getDefaultProperties()` (inherited values included) and `#[Table]` / `#[Connection]` via `class_exists`, including parent classes and direct traits. Null means undeclared and is then set from the storage prefix and `connectionName()`. `newInstance` / `newFromBuilder` copy `boundStorage`, table, and connection. Identity getters are `final`, read raw attributes, and reject non-canonical host keys through `HostKeyColumns::canonical` (string bytes kept, bigint leading zeros rejected, system actor `azguard.system` with null id decoded). `casts()` is merged after the `$casts` property, so service casts stay in place and `meta` stays `array`. `defaults.models` checks that the class exists in configuration and checks inheritance only in `Storage::model()`.

**P3.3 fields.** `Field` is `final`. Modifiers return clones. Names match `^[a-z][a-z0-9_]{0,63}$`. Reserved names and `tenant_` / `subject_` / `context_` / `actor_` prefixes throw `DefinitionException`. `enum` requires a `BackedEnum`. `model` requires an Eloquent subclass. `FieldTarget` is only `RoleGrant` and `PermissionGrant`. Panel `fields()` is additive; a duplicate name on one target throws with both origins (`provider` and `plugin:{id}`). `GrantFields` unions `azguardFields()` and panel fields, rejects a name collision with both origins, rejects unknown keys with `invalid_change_fields`, and uses Laravel `required`, `array` plus per-element type rules, `Rule::enum`, `Rule::exists` on the model key, `date`, and custom rules. `toRow` puts normal fields in `columns` and `inMeta` fields in `meta`; enums store `value`; dates become UTC ISO-8601. `decisionValues` returns only declared decision fields. `(bool) "0"` is `false` in PHP, so a Laravel-accepted boolean `"0"` stays false. `fillAndInsert`, `fillAndInsertOrIgnore`, and `fillAndInsertGetId` call the overridden `insert*` methods and throw.

**P3.4 guard, aside from R1.** Instance `save` / `saveQuietly` / `saveOrFail` / `saveOrIgnore`, `update*`, `push*`, `delete*`, `forceDelete`, `touch*`, `increment*` / `decrement*` including `*Each` and `*Quietly`, and static `destroy` are `final` and throw `unsupported_direct_write` before SQL, including under `withoutEvents()` and `fill()->save()`. The same holds for the builder methods the contract names, including `touch`, `incrementOrCreate`, and the `*Quietly` create methods. The message does not depend on the environment. Custom grant models inherit the trait. The arch token scan resolves aliases, grouped imports, fully qualified names, `self` / `static` / `parent`, and subclasses across scanned files; zones are `Storage\` and `Sources\Database\` for model static calls, plus `Changes\ChangePipeline` and `Testing\` for `StorageMutation`, and only `Storage\` for concerns and `WriteGuardedBuilder`. String class names and `($class)::method()` are outside that token scan; the contract asks for a token scan, and the zone test treats a string literal as not a call.

## Not claimed

The green logs under `artifacts/P3-models` and `artifacts/P3-direct-writes` were not re-opened or re-run. R1 is a path those datasets do not call. No fix is included.
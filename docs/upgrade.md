# Upgrading

## From 0.7 to 1.0 (instructions for AI agents)

1.0 finishes one contract transition from 0.7.0. There are no compatibility aliases: apply every line that matches
your code. Each line is `Find: <grep pattern> → Replace: <new form>`.

- Find: `@api` or `@spi` in a docblock of an AzGuard type → Replace: nothing; publication is the attribute
  `#[AzGuard\Attributes\Api(since: …)]` or `#[AzGuard\Attributes\Spi(kind: SpiKind::Implement|Call, since: …)]`, and a type
  without it is internal wherever it lives.
- Find: `"stability"` or `"via"` in a tool reading `api-manifest.json` → Replace: `"publication"` (`api`, `spi-implement`,
  `spi-call`) and `"since"`; entries also carry protected override points, constant values, `enum: closed|open`,
  PHPDoc `contracts`, `namedArguments`, and the manifest carries `roots` (core also `facade`).
- Find: `AzGuard\Scopes\AssignmentScopePhase` → Replace: `AzGuard\Context\AssignmentScopePhase`.
- Find: `AzGuard\Scopes\AssignmentScopeRuntime` → Replace: `AzGuard\Context\AssignmentScopeRuntime`.
- Find: `AzGuard\Scopes\TenantPolicy` → Replace: `AzGuard\Tenancy\TenantPolicy`.
- Find: `AzGuard\Scopes\ModelTenantDefinition` → Replace: `AzGuard\Tenancy\ModelTenantDefinition`.
- Find: `AzGuard\Contracts\Scopes\TenantDirectory` → Replace: `AzGuard\Contracts\Tenancy\TenantDirectory`.
- Find: `AzGuard\Contracts\Scopes\TenantMembership` → Replace: `AzGuard\Contracts\Tenancy\TenantMembership`.
- Find: `AzGuard\Contracts\Scopes\TenantResolver` → Replace: `AzGuard\Contracts\Tenancy\TenantResolver`.
- Find: `->tenants()->mode() === 'none'` (or `'required'`, any `TenantPolicy::mode()` string) → Replace:
  `=== AzGuard\Tenancy\TenantMode::None` (or `::Required`); `mode()` returns the closed enum `TenantMode`, its `->value` is the former string.
- Find: `AzGuard\AzGuardManager` → Replace: `AzGuard\AzGuard` (the container alias `azguard` and the `AzGuard` facade are unchanged).
- Find: `AzGuard::check(` or `AzGuard::authorize(` with a subject that is not a model or a `SubjectRef` → Replace: pass the
  Eloquent model or `SubjectRef::of($type, $id)`; the subject is typed `Model|Authenticatable|SubjectRef` and anything else is a `TypeError`.
- Find: `extends BasePlugin` or `AzGuard\Plugins\BasePlugin` → Replace: `implements AzGuard\Contracts\Plugins\Plugin`; `BasePlugin` is gone.
- Find: `function boot(Panel $panel, PluginContext $context)` in a plugin → Replace: nothing; `Plugin::boot()` is gone, a plugin only
  declares in `register()`, and a leftover `boot()` method compiles and is never called.
- Find: `DependsOnPlugins`, `function requires(): array` or `$context->dependencies()` or `PluginDependencyMissingException` → Replace:
  nothing; plugins no longer name other plugins, and `new PluginContext(...)` takes `panelId, pluginId, buildId` only.
- Find: a before hook, after hook or change pipe class → Replace: `implements AzGuard\Contracts\Authorization\BeforeHook` (`before(AccessRequest, EvaluationContext): BeforeResult`),
  `AfterHook` (`after(AccessRequest, EvaluationContext, Decision): void`) or `AzGuard\Contracts\Changes\ChangePipe` (`handle(Change, Closure): ChangeResult`); an invokable
  hook class or a pipe with only a `handle()` method is rejected when the panel compiles.
- Find: `->before(fn (` / `->after(fn (` / `->changing([fn (` closures with fewer parameters, untyped parameters or no return type → Replace: the full typed
  signature of the contract method (`before`: `AccessRequest $request, EvaluationContext $context): BeforeResult`; `after`: `..., Decision $decision): void`;
  `changing`: `Change $change, Closure $next): ChangeResult`); parameters are no longer injected by name.
- Find: `id()` returning a value that starts with `azguard/` in a class outside the namespace `AzGuard\` → Replace: your own vendor prefix; the prefix is reserved.
- Find: `AuditPlugin::retentionIn(` → Replace: `$panel->options(AzGuard\Audit\AuditOptions::class)?->retentionDays` (the plugin contributes the options object; `retentionIn()` is gone).
- Find: `->gate(` or `GateMode` → Replace: nothing; `PanelBuilder::gate()` and `AzGuard\Panels\GateMode` are gone, a panel always answers the abilities it owns authoritatively.
- Find: `defaults.gate.mode`, `'gate' => ['mode' =>` under `defaults`, or `PanelSettings::GATE_MODE` / `settings()->gateMode()` → Replace: remove it; the key is now an unknown-key error, and the doctor check `gate.mode` is gone (`gate.enabled` is unchanged).
- Find: `new PanelSettings(` with a `GateMode` argument → Replace: drop the argument (constructor is `@internal`).
- Find: a call of `Panel::changing()`, `before()`, `after()`, `restrictions()`, `grantConditions()`, `doctorChecks()`, `attachedSources()`, `scopeDefinition()`, `scopeDefinitions()`, `scopeResolvers()`, `tenantResolvers()` or `resourceScopes()` in application or extension code → Replace: nothing supported; these raw compiled lists are `@internal` now.
- Find: `Field::…('azg_…')` (a grant field whose name starts with `azg_`) → Replace: another name; the prefix `azg_` is reserved like `tenant_`, `subject_`, `context_` and `actor_`.
- Find: `StoresGrants` (an implementation, `instanceof`, or a source `describe()` that lists it) → Replace: nothing; the interface is gone, `DatabaseSource` is the only writer, and a source that only reads has no writer capability.
- Find: `$panel->writer()` that expects a source (`->apply(`, `->transaction(`, `instanceof DatabaseSource`, `->isDynamic()`) → Replace: `$panel->writer()` returns `?AzGuard\Schema\WriterSchema` (`storage`, `dynamicPermissions`, `rolesOnly`; null for a read-only panel); read capabilities from it or from `PanelSchema::writer()`.
- Find: the capability string `StoresGrants` in `azguard:panels:list` output or in `SourceDescription::$capabilities` → Replace: use the `writer` column and `WriterSchema`.
- Find: `SourceContractTests` `writerStoresOnlyInsideThePipelineTransaction` → Replace: nothing; the writer case left the shipped contract suite.
- Find: a `WriterConflictException` caught for two different writer ids → Replace: it is thrown when the database source is attached twice.
- Find: `AssignmentScopePolicy` → Replace: `AzGuard\Scopes\ScopesPlugin`, attached as a plugin: `->scopes(AssignmentScopePolicy::inherit(X))` becomes `->plugins([ScopesPlugin::inherit(X)])` (also `isolated`, `required`; `requireMembership()` and `accessAdapter()` are unchanged).
- Find: `AssignmentScopePolicy::none()` → Replace: nothing (a panel without `ScopesPlugin` is mode `None`); to switch off scopes an earlier layer declared, `->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, []))`.
- Find: `->scopes(` in a plugin `register()`, `configurePanels()` or a provider that already attaches `ScopesPlugin` → Replace: `->assignmentScopes(new AzGuard\Context\AssignmentScopeDeclaration(AssignmentScopeMode::…, [definition objects]))`; a panel takes one `ScopesPlugin` (id `azguard/scopes`), which also supplies the evaluator that applies filters and adapters.
- Find: a panel provider whose `->scopes(…)` decided the mode against a plugin that also declared scopes → Replace: attach `ScopesPlugin` and settle the mode in the provider with `->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::…, []))`; `ScopesPlugin` contributes on the plugin layer, and two plugins with different modes are a conflict.
- Find: `ScopesPlugin::inherit(Definition::class)` (or `isolated`/`required`) with a definition class that has constructor arguments or a container binding → Replace: pass the configured definition object; classes are created without arguments.
- Find: `->accessAdapter(` or `->requireMembership(` given an object that holds a non-static closure (`fn` or `function` inside a class) → Replace: a static closure or a class name; adapters and memberships are panel options now and cannot capture an object.
- Find: `AzGuard\Authorization\ScopeEligibility` or custom scope eligibility code → Replace: implement `AzGuard\Contracts\Scopes\AssignmentScopeEvaluator` (`validate`, `allows`, `allowsMany`) and name it in `AssignmentScopeDeclaration::$evaluator`; a declaration without an evaluator checks scopes structurally only and rejects filtered definitions unless its mode is `None`.
- Find: the doctor check key `membership.configured` used for scope membership or owner findings (for example in a `--check` filter) → Replace: key `scopes.membership`, contributed by `ScopesPlugin`; the finding id stays `membership.configured`, its scope is `plugin:azguard/scopes` with the panel in the details, and `membership.configured` now checks tenant membership only.
- Find: code that expects scope membership to be checked before policies or at panel entry (`requireMembership()` of `ScopesPlugin`) → Replace: scope membership is the panel restriction `membership` (reason `assignment_scope_membership`) that runs with the other restrictions after authority; panel admission checks tenant membership only.
- Find: a custom assignment scope directory, or a scope type that relied on the default directory (`QueryScopeDirectory`) without `ScopesPlugin` → Replace: name it per type in the declaration (`AssignmentScopeDeclaration::$directories`) or on the definition (`->directory(...)`, which wins); `ScopesPlugin` adds `QueryScopeDirectory` for queryable definitions, and a type without a directory offers and describes nothing.
- Find: code that expects a scope directory to offer contexts the write later refuses (another tenant the target is not a member of, a panel in mode `None`, a scope the target is not a member of) → Replace: nothing; in the Assignment phase `PanelDirectories::scopes()` applies the checks of the write, and `describe()` revalidates a context the same way.
- Find: a resource model with `azguardContextType()` (or `use ContextAware;`) → Replace: also declare `implements AzGuard\Contracts\Scopes\MapsAssignmentScope`; lists map a resource to its context only through that contract.
- Find: `RelationSource::make(` for a scope type that only a role `scopes()` or a `Scopes/` folder class names → Replace: declare the type in the scope declaration of the panel (`ScopesPlugin` or `assignmentScopes()`); a relation source binds only declared queryable types.
- Find: `visibleTo(` on scoped resources of a panel whose declaration names no evaluator, or one that does not implement `QueryableAssignmentScopeEvaluator` → Replace: attach `ScopesPlugin` or implement the queryable evaluator; otherwise the list raises `VisibilityNotSupportedException` with reason `scope_query`.
- Find: a custom `FiltersQueries` source whose tenant-wide witnesses hold in every assignment scope (like `#[GrantedToAll]`) → Replace: return `AssignmentScopeSelection::inEveryContext($witnesses)`; `everywhere()` witnesses do not reach isolated scopes.
- Find: `Panel::scopes()`, `->scopes()->mode()` or a scope mode string `'none'`/`'inherit'`/`'isolated'`/`'required'` → Replace: `$panel->scopeDeclaration()?->mode` (`AzGuard\Context\AssignmentScopeMode`; a null declaration is `None`); membership and access adapters are no longer readable from the panel.
- Find: `AzGuard\Changes\ChangeJournal` (`append()`, `row()`, `COLUMNS`) or a change pipe that writes journal rows → Replace: a
  `AzGuard\Contracts\Changes\ChangeParticipant` registered with `->participants([...])`; it receives `ChangeRecord`s (final change, effect,
  dispatched event) once per operation with effects and appends to its declared tables through `OwnedRowsWriter::insert()`.
- Find: `DatabaseSource::appendJournal(` → Replace: nothing; journal rows are written by the participant of `AuditPlugin` (or your own participant).
- Find: `AuditPlugin` on a panel without `DatabaseSource` → Replace: attach the database writer or drop the plugin; a panel without the writer
  cannot take participants and fails to compile with `DefinitionException`.

## From 0.3 to 0.7.0

Version 0.3 (`axioma-studio/azguard-core`, `-context`, `-filament`, tag `v0.3.0`) was never published on
Packagist. Only upgrades from 0.3 are covered; upgrade an older install to 0.3 first. 0.7.0 is a redesign under new package names. There is no in-place upgrade: install 0.7.0, port the code,
then copy the role assignments.

### What changed

| 0.3 | 0.7.0 |
|---|---|
| `axioma-studio/azguard-core`, `-filament`, `-context` | `axiomasoft/azguard`, `axiomasoft/azguard-filament`. Tenants and scopes are in the core |
| `config/az-guard.php` | `config/azguard.php` (`php artisan azguard:install`) |
| `roles` table and role rows | Roles are PHP classes only. Grants are stored in `azg_role_grants` |
| `model_has_roles`, `model_has_scopes` | `azg_role_grants`, with an optional assignment scope per grant |
| `az_direct_grants`, `az_guard_role_permissions` | `azg_permission_grants`. A role's permissions are declared on the role class |
| `assignRole()`, `removeRole()` | `grantRole()`, `revokeRole()` (with `on:` and `until:`) |
| `assignScopedRole()`, `hasScopedRole()` | `grantRole($role, on: $team)`, `hasRole($role, on: $team)` |
| `grant()`, `revoke()`, `hasGrant()` | `grantPermission()`, `revokePermission()`, `hasPermission()` |
| `checkPermission()` | `hasPermission()` or `AzGuard::authorize()` |
| `check.access` middleware | `azguard.panel:{id}` and `azguard.can:{permission}` |
| `grant_sources`, `GrantSource` | Panel sources: `permissions([...])`, `ProvidesGrants` ([Sources](/advanced/sources)) |
| `guard:*` commands | `azguard:*` ([Commands](/reference/commands)) |
| `cache.store = 'array'` | `cache.store = null` (memoized for the request) or a Laravel store |

### Steps

1. Require `axiomasoft/azguard` and run `php artisan azguard:install --panel=Admin --migrate`.
2. Move permission enums into `app/Guards/{Panel}/Permissions` and roles into `Roles`. Give every role an
   explicit `#[Role('key')]`. Run `php artisan azguard:doctor` until it reports no errors.
3. Copy role assignments. The script below was tested against the 0.3 schema. Map every old role name to a
   0.7.0 role class explicitly, so an unknown name stops the migration instead of being dropped silently:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

$map = ['editor' => EditorRole::class, 'super-admin' => SuperAdminRole::class];

AzGuard::actingAs('migration from 0.3', function () use ($map): void {
    DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->select('roles.name', 'model_has_roles.model_type', 'model_has_roles.model_id')
        ->orderBy('model_has_roles.model_id')
        ->lazy()
        ->each(function (object $row) use ($map): void {
            $role = $map[$row->name] ?? throw new RuntimeException("No 0.7.0 role for {$row->name}");
            $class = Relation::getMorphedModel($row->model_type) ?? $row->model_type;

            if (! is_subclass_of($class, Model::class)) {
                throw new RuntimeException("Unknown subject type {$row->model_type}");
            }
            $subject = ModelKey::find((new $class)->newQuery(), (string) $row->model_id);

            if ($subject === null) {
                logger()->warning('azguard migration: subject not found', (array) $row);

                return;
            }

            $subject->grantRole($role);   // repeatable: a second run changes nothing
        });
});
```

   Scoped roles (`model_has_scopes`) and direct grants work the same way, with
   `grantRole($role, on: $scopeModel)` and `grantPermission($permission)`.

4. Keep the old tables until the application has run on 0.7.0 for a while, then drop them.

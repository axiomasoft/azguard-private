# Configuration

`php artisan azguard:install` publishes `config/azguard.php`. The configuration is validated at boot:

- an unknown key raises `InvalidConfigurationException`, which names the key;
- a value of the wrong type raises the same exception.

A typo therefore fails at boot instead of being ignored.

## Where a panel setting comes from

A panel setting is resolved from these layers. The first layer that sets it wins:

1. the panel provider (`PanelBuilder`);
2. the panel's plugins;
3. `AzGuard::configurePanels(fn (PanelBuilder $panel) => ...)` in a service provider;
4. `azguard.defaults` in the configuration.

`php artisan azguard:panels:list --settings` prints each value together with the layer it came from.

## `config/azguard.php`

| Key | Default | Meaning |
|---|---|---|
| `panels.providers` | `[]` | Panel provider classes. `azguard:install` and `azguard:make:panel` add them here. Module providers can also register themselves |
| `defaults.models.role_grant` | `AzGuard\Storage\Models\RoleGrant` | Eloquent model of role grants. `azguard:make:models` creates a subclass with custom fields |
| `defaults.models.permission_grant` | `…\PermissionGrant` | Model of direct permission grants |
| `defaults.models.permission` | `…\Permission` | Model of dynamic permissions |
| `defaults.resource_prefix` | `true` | Prefix of full permission names: `true` uses the panel id (`admin.posts.view`), `false` uses no prefix, and a string is set per panel with `resourcePrefix()` |
| `defaults.gate.mode` | `authoritative` | `GateMode`. The panel's answer for an ability it owns is final. Abilities it does not own are left to Laravel |
| `defaults.cache.store` | `null` | Laravel cache store for permission sets. With `null`, sets are memoized for the current request or job only |
| `defaults.cache.ttl` | `3600` | Seconds a set stays in the store |
| `defaults.cache.generation` | `1` | Part of every cache key. Raise it to drop all cached sets |
| `defaults.consistency.reads` | `primary` | `Reads::Primary` reads grants from the write connection. `Reads::Default` uses the read replica and accepts replica lag |
| `defaults.consistency.state_refresh` | `request` | `StateRefresh::Request` re-reads the state version once per request or job. `StateRefresh::Check` re-reads it before every check |
| `defaults.trace_decisions` | `false` | Dispatch `AccessDecided` on every check (diagnostics; not available on `PanelBuilder`) |
| `defaults.tenants.resolvers` | `[]` | Tenant resolver classes tried after a panel's own resolvers |
| `defaults.scopes.resolvers` | `[]` | Assignment scope resolver classes tried after a panel's own resolvers |
| `gate.enabled` | `true` | `false` stops registering `Gate::before`. `@can` and `can()` then no longer see AzGuard permissions; `AzGuard::check()` and the middleware still work |
| `decision_sets.max_subjects` | `500` | Most distinct subjects of one `decideMany()`. Its reads share one snapshot. A larger set throws `DecisionSetTooLargeException` and is never split silently (see [Consistency](/advanced/consistency#decisionset)) |
| `schedule.enabled` | `true` | Register scheduled pruning of expired grants |
| `schedule.prune_expired` | `daily` | A scheduler frequency method name (`hourly`, `daily`, `weekly`), a cron expression, or `null` |
| `catalog.build_id` | `env('AZGUARD_BUILD_ID')` | Names the deployed build. Cached catalogs of another build are never used. Set it to the commit hash on every deploy |
| `catalog.cache_path` | `null` | File for `azguard:catalog:cache`. The default is `bootstrap/cache/azguard.php` |
| `storages.default.connection` | `env('AZGUARD_DB_CONNECTION')` | Database connection of the AzGuard tables (`null` is the default connection). See [Checks inside transactions](/concepts/decisions#checks-inside-database-transactions) |
| `storages.default.table_prefix` | `azg_` | Table prefix |
| `storages.default.host_keys` | `null` | Key type of subjects and resources: `string`, `bigint`, `uuid` or `ulid`. `null` uses `ids.host_keys` |
| `ids.host_keys` | `string` | Default key type for storages. `string` accepts every key type |
| `sources.{name}` | — | Parameters of a named source (see [Sources](/advanced/sources)) |
| `discovery.*` | `Permissions`, `Policies`, `Roles`, `Scopes`, `Abilities`, `Queries`, `Shared` | Folder names relative to a panel provider. `Shared` is a sibling of the panel directories that holds `#[AsSource]` sources |
| `scaffold.namespace` / `scaffold.path` | `App\Guards` / `app/Guards` | Where the generators create panels |

### Environment variables

| Variable | Used by |
|---|---|
| `AZGUARD_DB_CONNECTION` | `storages.default.connection` |
| `AZGUARD_BUILD_ID` | `catalog.build_id` |

## Panel settings (`PanelBuilder`)

| Method | Purpose |
|---|---|
| `label()`, `description()` | Display name and description (CLI, Filament) |
| `default()` | The panel used when nothing else selects one |
| `resourcePrefix(string\|bool)` | Prefix of full permission names |
| `for(Model::class, guard:, directory:)` | Subject models of the panel; `guard` is the auth guard |
| `middleware([...])`, `entry(Permission)`, `onDenied(...)`, `requireRouteChecks()` | Panel routes: see [Checking access](/guides/checking-access#routes) |
| `permissions([...])` | Permission sources: enum classes, `DatabaseSource`, `GateSource`, named sources |
| `roles([...])` | Role classes in addition to the `Roles` folder, e.g. `SuperAdminRole::class` |
| `policies([...])` | Policy bindings beyond folder pairing (`PolicyBinding::for()`, `PolicyBinding::gate()`) |
| `discover(path, namespace)` | Another directory with `Permissions`, `Policies` and `Roles` folders |
| `presentation([...])` | Labels and groups for the CLI and Filament |
| `fields(FieldTarget, [...])` | Custom grant fields |
| `before()`, `restrictions()`, `after()` | Decision hooks: see [Hooks and plugins](/advanced/hooks-and-plugins) |
| `changing([...])`, `grantConditions([...])` | Change pipes and grant conditions |
| `doctorChecks([...])` | Extra `azguard:doctor` checks |
| `gate(GateMode)`, `cache(store, ttl, generation)`, `consistency(Reads, StateRefresh)` | Same as `defaults.*` |
| `tenants(TenantPolicy)`, `scopes(AssignmentScopePolicy)` | Tenants and assignment scopes: see [Tenants and scopes](/guides/tenants-and-scopes) |
| `tenantResolvers()`, `scopeResolvers()`, `resourceScopes()` | Resolvers for the current tenant, the assignment scope and the scope of a resource |
| `plugins([...])`, `withoutPlugins([...])` | Panel plugins |

## `config/azguard-filament.php`

Published with `php artisan vendor:publish --tag=azguard-filament-config`. These are the defaults for every
`AzGuardPlugin` instance. A value set on the instance wins.

| Key | Default | Meaning |
|---|---|---|
| `guard_panel` | `null` | The AzGuard panel that holds the permissions of the Filament panel |
| `manages` | `null` | Panels the editors manage. `null` means every panel with a writable schema |
| `enforce` | `true` | Every resource, page and widget must be decided by AzGuard or excluded |
| `definitions` | `enums` | `enums` (Permissions folder) or `resources` (read from Filament classes by `FilamentSource`) |
| `authority` | `grants` | Authority of `resources` definitions: `grants` or `policy` |
| `abilities` | `view_any` … `reorder` | Resource abilities, in snake_case |
| `exclude.resources` / `.pages` / `.widgets` | `[]` | Classes that get no permission |

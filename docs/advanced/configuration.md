# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=az-guard-config
```

This creates `config/az-guard.php`. Below is the full file with annotations.

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extension Points
    |--------------------------------------------------------------------------
    | Swappable single-active-strategy seams — override with your own
    | subclass to swap implementation-wide. See "Swapping core services"
    | in Extending for a worked example of each.
    */
    'manager' => \AzGuard\AzGuardManager::class,
    'resolver' => \AzGuard\Registry\Resolver\EffectivePermissionResolver::class,
    'matcher' => \AzGuard\Registry\Matching\WildcardPermissionMatcher::class,
    'abilities_resolver' => \AzGuard\Abilities\DefaultAbilitiesResolver::class,
    'role_permission_validator' => \AzGuard\Registry\Validation\CatalogRolePermissionValidator::class,

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Eloquent models used by AzGuard. Replace with your own subclasses.
    */
    'models' => [
        'role'            => \AzGuard\Models\Role::class,
        'scope'           => \AzGuard\Models\ModelHasScope::class,
        'direct_grant'    => \AzGuard\Models\DirectGrant::class,
        'role_permission' => \AzGuard\Models\RolePermission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models Namespace
    |--------------------------------------------------------------------------
    | Application namespace used when resolving model classes by name.
    */
    'models_namespace' => 'App\\Models\\',

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    | Override if existing tables conflict.
    */
    'table_names' => [
        'roles'            => 'roles',
        'model_has_roles'  => 'model_has_roles',
        'model_has_scopes' => 'model_has_scopes',
        'role_permissions' => 'az_guard_role_permissions',
        'direct_grants'    => 'az_direct_grants',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    | morph_type sets the key type for AzGuard's polymorphic columns:
    | 'int' (default), 'ulid' or 'uuid'. Match it to your User's primary key.
    | Any other value throws InvalidMorphTypeException at boot (fail-fast).
    */
    'column_names' => [
        'morph_type' => env('AZ_GUARD_MORPH_TYPE', 'int'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    | List every class that extends AzGuard\PanelProvider.
    | Each panel defines its own permission namespace and catalog builders.
    */
    'panels' => [
        // \App\Guards\App\AppGuardPanelProvider::class,
        // \App\Guards\Admin\AdminGuardPanelProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Panel
    |--------------------------------------------------------------------------
    | Panel id used for authorization and permission resolution when no panel
    | is active on the current request (console commands, queued jobs, or
    | routes without the azguard.panel middleware). Leave null to refuse to
    | guess: with no active panel and more than one registered panel, checks
    | deny (fail-closed) rather than evaluate against an arbitrary panel.
    |
    | The model permission APIs ($user->hasPermission() etc.) fall back to
    | the built-in 'app' panel when this is null — set it explicitly to
    | change that project-wide. The Gate/Authorizer path stays fail-closed.
    */
    'default_panel' => null,

    /*
    |--------------------------------------------------------------------------
    | Strict Panels
    |--------------------------------------------------------------------------
    | Opt-in. When true, resolving an explicit but unregistered panel throws
    | PanelNotFoundException instead of the default lenient (best-effort)
    | resolution against an empty catalog. Off by default for back-compat.
    */
    'strict_panels' => false,

    /*
    |--------------------------------------------------------------------------
    | Scope (query-scope isolation)
    |--------------------------------------------------------------------------
    | on_missing_panel controls what the HasScopedRoles query-scope global
    | scope does when NO panel is currently active at all (e.g. a queue job
    | that never set one):
    |   'exception' (default) — throw PanelNotSetException (fail-closed).
    |   'empty'               — the query returns no rows.
    |   'all'                 — apply every scope regardless of panel_id
    |                           (pre-0.3 aggregate behaviour) — explicit opt-out.
    */
    'scope' => [
        'on_missing_panel' => 'exception',
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'check_access_alias' => 'check.access',
        'register_middleware_in_appServiceProvider' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | Per-user permission caching across requests.
    | store: any store from config/cache.php, or 'array' to disable
    |        cross-request caching (in-memory only — the default, and what
    |        you want in tests).
    | expiration_time: TTL in seconds. null = never expires — but only on
    |        the 'array'/'null' stores; AzGuard refuses to boot with a null
    |        TTL on a persistent store (InvalidCacheConfigException), since
    |        the per-user epoch key would then never expire either.
    */
    'cache' => [
        'store' => 'array',
        'expiration_time' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Sources
    |--------------------------------------------------------------------------
    | Control which GrantSources are active and their priority order.
    | null (default) = all built-in sources active, sorted by GrantPriority enum.
    | Provide an explicit list to restrict or reorder:
    |
    |   'grant_sources' => [
    |       \AzGuard\Registry\Sources\ClassRoleGrantSource::class,
    |       \AzGuard\Registry\Sources\DatabaseRoleGrantSource::class,
    |       // Omit DirectGrantSource to disable direct grants at source level
    |   ],
    */
    'grant_sources' => null,

    /*
    |--------------------------------------------------------------------------
    | Fail-fast on GrantSource Exception
    |--------------------------------------------------------------------------
    | When true, any Throwable from a GrantSource::permissionsFor() call
    | propagates immediately, failing the entire authorization pipeline.
    | When false (default), the failing source is skipped and a warning is
    | logged. Recommended: true in tests, false in production.
    */
    'fail_on_source_exception' => false,

    /*
    |--------------------------------------------------------------------------
    | Prune Expired Direct Grants
    |--------------------------------------------------------------------------
    | When true, AzGuard registers a daily scheduled task that runs
    | `guard:prune-grants` to delete expired direct grants. Off by default —
    | schedule the command yourself if you prefer explicit control. Expired
    | grants are never honoured regardless (the active() scope filters
    | them); pruning only keeps the table tidy.
    */
    'prune_expired_daily' => false,

    /*
    |--------------------------------------------------------------------------
    | Features (Feature Flags)
    |--------------------------------------------------------------------------
    | Enable only what you need. All flags default to false (except
    | direct_grants) for maximum backwards compatibility.
    */
    'features' => [
        'wildcard_permission' => false,        // Wildcards like 'admin.*'
        'teams' => false,                      // Multi-team / tenant isolation
        'audit_log' => false,                  // Dispatch AccessDecision from Authorizer::explain()
        'direct_grants' => true,                // Direct grants (HasDirectGrants + az_direct_grants)
        'validate_role_permissions' => false,  // Vet RolePermission keys against the catalog on save
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    | Settings for multi-team mode (requires features.teams = true).
    */
    'teams' => [
        'foreign_key' => 'team_id',
    ],

];
```

::: tip Tests
Set `cache.store` to `'array'` (the default) to keep permissions in-memory only, which prevents stale state between test cases.
:::

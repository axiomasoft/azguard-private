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
    | Models
    |--------------------------------------------------------------------------
    | Eloquent models used by AzGuard. Replace with your own subclasses.
    */
    'models' => [
        'role'         => \AzGuard\Models\Role::class,
        'scope'        => \AzGuard\Models\ModelHasScope::class,
        'direct_grant' => \AzGuard\Models\DirectGrant::class,
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
    */
    'column_names' => [
        'morph_type' => 'int',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | 'store'  — any store from config/cache.php. Use 'array' to disable
    |            cross-request caching (in-memory only, good for tests).
    | 'expiration_time' — TTL in seconds. null = never expires.
    */
    'cache' => [
        'store'           => 'array',
        'expiration_time' => 3600,
        'key'             => 'azguard.permissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    | Toggle optional capabilities. All disabled by default for maximum
    | backwards compatibility.
    */
    'features' => [
        'wildcard_permission' => false,
        'direct_grants'       => true,
    ],

];
```

::: tip Tests
Set `cache.store` to `'array'` (the default) to keep permissions in-memory only, which prevents stale state between test cases.
:::

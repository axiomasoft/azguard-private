<?php

declare(strict_types=1);
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;

return [

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | Panel providers of the application. A panel is a directory: its provider
    | describes the panel, and the permissions, policies and roles of the
    | panel live next to it. Providers of modules register themselves.
    |
    */

    'panels' => [
        'providers' => [
            // App\Guards\Admin\Panel::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for every panel
    |--------------------------------------------------------------------------
    |
    | A panel takes a setting from its provider, then from its plugins, then
    | from AzGuard::configurePanels(), and only then from here. The command
    | azguard:panels:list --settings shows where each value came from.
    |
    */

    'defaults' => [

        'models' => [
            'role_grant' => RoleGrant::class,
            'permission_grant' => PermissionGrant::class,
            'permission' => Permission::class,
        ],

        // Prefix of permission names: true is the panel id (admin.orders.view),
        // false turns it off. A custom prefix is set on the panel.
        'resource_prefix' => true,

        'gate' => [
            // authoritative: the answer for an ability the panel owns is final.
            'mode' => 'authoritative',
        ],

        'cache' => [
            // A Laravel cache store; null keeps permission sets for the request only.
            'store' => null,
            'ttl' => 3600,
            'generation' => 1,
        ],

        'consistency' => [
            'reads' => 'primary',           // primary | default
            'state_refresh' => 'request',   // request | check
        ],

        // Dispatch a decision event on every check, for diagnostics.
        'trace_decisions' => false,

    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    |
    | The build id names the deployed code and configuration: cached panel data
    | of another build is never used. Set it on every deployment, for example
    | to the commit hash. When it is empty the package derives an id from the
    | files of the panel providers, which does not follow other code.
    |
    | azguard:catalog:cache writes the static catalogs of all panels to the
    | cache path (bootstrap/cache/azguard.php when it is null); a panel uses
    | the file only when it was written by the same build for the same panel.
    |
    */

    'catalog' => [
        'build_id' => env('AZGUARD_BUILD_ID'),
        'cache_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Named sources
    |--------------------------------------------------------------------------
    |
    | Parameters passed to a source when a panel names it in permissions([...]).
    | The name is registered with #[AsSource] or AzGuard::sources()->extend().
    | A name without an entry is created with an empty array.
    |
    */

    'storages' => [
        'default' => [
            'connection' => env('AZGUARD_DB_CONNECTION'),
            'table_prefix' => 'azg_',
            'host_keys' => null,
        ],
    ],

    'ids' => ['host_keys' => 'string'],

    'sources' => [
        // 'ldap' => ['group_attribute' => 'memberOf'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Folder names, relative to a panel provider or a discover() directory.
    | Permissions and policies must be different directories, and neither may
    | sit inside the other. Shared is the sibling of a panel directory: it is
    | not a panel, and only its Sources/ classes that carry #[AsSource] are
    | registered.
    |
    */

    'discovery' => [
        'permissions' => 'Permissions',
        'policies' => 'Policies',
        'roles' => 'Roles',
        'scopes' => 'Scopes',
        'abilities' => 'Abilities',
        'queries' => 'Queries',
        'shared' => 'Shared',
    ],

];

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults of the AzGuard plugin
    |--------------------------------------------------------------------------
    |
    | The plugin of a Filament panel reads these values once, when it is made;
    | what the plugin sets on its instance wins. The configuration is never
    | written at run time, so two Filament panels never share state.
    |
    */

    // Id of the AzGuard panel that holds the permissions of this Filament panel.
    'guard_panel' => null,

    // AzGuard panels whose roles and grants this admin edits; null means every
    // panel with a writable schema.
    'manages' => null,

    // Resources, pages and widgets without a permission are closed.
    'enforce' => true,

    // Where the permissions of resources, pages and widgets are described:
    // 'enums' (Permissions folder of the guard panel) or 'resources' (read from
    // the Filament classes by FilamentSource).
    'definitions' => 'enums',

    // Who decides the permissions of the 'resources' definitions: 'grants'
    // (assignments, optional policy veto) or 'policy' (the policy alone).
    'authority' => 'grants',

    // Abilities of a resource, in snake_case.
    'abilities' => [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'delete_any',
        'force_delete',
        'force_delete_any',
        'restore',
        'restore_any',
        'replicate',
        'reorder',
    ],

    // Classes that get no permission.
    'exclude' => [
        'resources' => [],
        'pages' => [],
        'widgets' => [],
    ],

];

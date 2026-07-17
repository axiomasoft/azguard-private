<?php

declare(strict_types=1);

use Filament\Resources\Pages\CreateRecord;

return [
    /*
    |--------------------------------------------------------------------------
    | Linked AzGuard panel
    |--------------------------------------------------------------------------
    |
    | The AzGuard panel id whose permission catalog backs this Filament panel.
    | Discovered Filament resources/pages register their permissions into this
    | panel, and resource authorization is checked against it.
    |
    */
    'panel' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    |
    | When true, the plugin authorizes every discovered resource against its
    | generated permission with zero code in the resources: it disables
    | Filament's policy-existence shortcut and answers resource Gate checks
    | from the user's AzGuard permissions. Set false to manage authorization
    | yourself (e.g. with generated policies).
    |
    */
    'enforce' => true,

    /*
    |--------------------------------------------------------------------------
    | Permission source
    |--------------------------------------------------------------------------
    |
    | Discovered keys are always registered in the catalog (so they show up in
    | the Role UI and can be granted). The source decides how access is
    | *enforced* and what code, if any, is generated:
    |   'database' — the runtime gate enforces; nothing is generated.
    |   'enum'     — `guard:filament:generate` writes a permission enum per
    |                resource (typed, IDE-friendly), still enforced by the gate.
    |   'policy'   — `guard:filament:generate` writes a Laravel policy per
    |                resource; Filament's native authorization enforces them and
    |                the runtime gate steps aside.
    |
    */
    'source' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Abilities generated per resource
    |--------------------------------------------------------------------------
    |
    | Each ability becomes its own permission key. Trim this list to scope
    | down, or add custom abilities.
    |
    */
    'abilities' => [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'force_delete',
        'replicate',
        'reorder',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages and widgets
    |--------------------------------------------------------------------------
    |
    | Discovered custom pages/widgets get a catalogued `{panel}.{page}.view` /
    | `{panel}.{widget}.view` permission, but — unlike resources — it is NOT
    | enforced automatically: Filament routes pages/widgets through their own
    | static canAccess()/canView(), which never touch the Gate. Add
    | AzGuard\Filament\Concerns\HasAzGuardPage / HasAzGuardWidget to a page or
    | widget class to make it actually enforce the permission. Hiding the nav
    | link (e.g. via shouldRegisterNavigation()) is NOT access control — the
    | URL stays reachable without the trait.
    |
    */
    'pages' => [
        'ability' => 'view',
    ],

    'widgets' => [
        'ability' => 'view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission key scheme
    |--------------------------------------------------------------------------
    |
    | Template for a resolved permission key. Placeholders: {panel}, {resource},
    | {ability}. `case` formats the {resource} segment (snake|kebab|camel|none).
    |
    */
    'key' => '{panel}.{resource}.{ability}',
    'case' => 'snake',

    /*
    |--------------------------------------------------------------------------
    | Discovery exclusions
    |--------------------------------------------------------------------------
    |
    | Fully-qualified resource/page/widget class names to skip during
    | discovery and generation.
    |
    */
    'exclude' => [
        'resources' => [],
        'pages' => [
            CreateRecord::class,
        ],
        'widgets' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | User label column
    |--------------------------------------------------------------------------
    |
    | Column name used to display user names in the Direct Grant and Role UIs.
    | Customize this if your user model uses a different column (e.g., 'email'
    | instead of 'name').
    |
    */
    'user_label_column' => 'name',

    /*
    |--------------------------------------------------------------------------
    | Super admin
    |--------------------------------------------------------------------------
    |
    | Role name that bypasses every resource check (null = rely on the panel's
    | wildcard role, e.g. SuperAdminRole).
    |
    */
    'super_admin' => null,

    /*
    |--------------------------------------------------------------------------
    | Code generation targets (source = enum | policy)
    |--------------------------------------------------------------------------
    */
    'generation' => [
        'enum_namespace' => 'App\\Guards\\Admin\\Permissions',
        'enum_path' => 'app/Guards/Admin/Permissions',
        'policy_namespace' => 'App\\Policies',
        'policy_path' => 'app/Policies',
    ],
];

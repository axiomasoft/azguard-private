<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** The permissions of the editors of the package in the admin and teams guard panels. */
#[RequiresGrant]
enum EditorPermission: string
{
    case RolesViewAny = 'azguard-roles.view_any';
    case RolesView = 'azguard-roles.view';
    case PermissionsViewAny = 'azguard-permissions.view_any';
    case PermissionsView = 'azguard-permissions.view';
    case PermissionsCreate = 'azguard-permissions.create';
    case PermissionsUpdate = 'azguard-permissions.update';
    case PermissionsDelete = 'azguard-permissions.delete';
}

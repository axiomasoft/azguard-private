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
    case RoleGrantsViewAny = 'azguard-role-grants.view_any';
    case RoleGrantsView = 'azguard-role-grants.view';
    case RoleGrantsCreate = 'azguard-role-grants.create';
    case RoleGrantsUpdate = 'azguard-role-grants.update';
    case RoleGrantsDelete = 'azguard-role-grants.delete';
    case PermissionGrantsViewAny = 'azguard-permission-grants.view_any';
    case PermissionGrantsView = 'azguard-permission-grants.view';
    case PermissionGrantsCreate = 'azguard-permission-grants.create';
    case PermissionGrantsUpdate = 'azguard-permission-grants.update';
    case PermissionGrantsDelete = 'azguard-permission-grants.delete';
    case PanelsPage = 'pages.azguard-panels';
    case DoctorPage = 'pages.azguard-doctor';
}

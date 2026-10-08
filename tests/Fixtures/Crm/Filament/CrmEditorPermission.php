<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Permissions\RequiresGrant;

/** The permissions of the grant editors of the CRM Filament panel in the guard panel `crm`. */
#[RequiresGrant]
enum CrmEditorPermission: string
{
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
}

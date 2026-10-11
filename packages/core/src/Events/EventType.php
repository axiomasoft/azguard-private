<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Attributes\Api;

/**
 * The stable machine name of each access event; the value is part of the integration contract.
 *
 * @open
 */
#[Api(since: '0.7')]
enum EventType: string
{
    case PermissionCreated = 'permission.created';
    case PermissionUpdated = 'permission.updated';
    case PermissionDeleted = 'permission.deleted';
    case RoleGranted = 'role.granted';
    case RoleRevoked = 'role.revoked';
    case RoleGrantUpdated = 'role.grant_updated';
    case PermissionGranted = 'permission.granted';
    case PermissionRevoked = 'permission.revoked';
    case PermissionGrantUpdated = 'permission.grant_updated';
    case GrantExpired = 'grant.expired';
    case PanelTouched = 'panel.touched';
    case AccessDecided = 'access.decided';
}

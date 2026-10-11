<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Attributes\Api;

/**
 * @closed
 */
#[Api(since: '0.7')]
enum FieldTarget: string
{
    case RoleGrant = 'role_grant';
    case PermissionGrant = 'permission_grant';
}

<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use Packman\Attributes\Api;

/**
 * @closed
 */
#[Api(since: '1.0')]
enum FieldTarget: string
{
    case RoleGrant = 'role_grant';
    case PermissionGrant = 'permission_grant';
}

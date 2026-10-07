<?php

declare(strict_types=1);

namespace AzGuard\Schema;

enum FieldTarget: string
{
    case RoleGrant = 'role_grant';
    case PermissionGrant = 'permission_grant';
}

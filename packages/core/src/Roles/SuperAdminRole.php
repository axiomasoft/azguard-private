<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use Packman\Attributes\Api;

/**
 * Ready-made super-admin role, attached to a panel explicitly; it needs no permissions.
 */
#[Role('superadmin', label: 'azguard::roles.superadmin')]
#[SuperAdmin]
#[Api(since: '1.0')]
final class SuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

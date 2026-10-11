<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Attributes\Api;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;

/**
 * Ready-made super-admin role, attached to a panel explicitly; it needs no permissions.
 */
#[Role('superadmin', label: 'azguard::roles.superadmin')]
#[SuperAdmin]
#[Api(since: '0.7')]
final class SuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

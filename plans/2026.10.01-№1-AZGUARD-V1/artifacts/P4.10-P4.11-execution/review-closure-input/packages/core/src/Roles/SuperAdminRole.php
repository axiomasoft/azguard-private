<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;

/**
 * Ready-made super-admin role, attached to a panel explicitly; it needs no permissions.
 *
 * @api
 */
#[Role('superadmin', label: 'azguard::roles.superadmin')]
#[SuperAdmin]
final class SuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

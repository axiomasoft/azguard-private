<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Permissions\PermissionKey;
use Override;

/**
 * Built-in super-admin role.
 *
 * The wildcard permission ({@see PermissionKey::WILDCARD}) grants access to
 * everything via Gate::before().
 *
 * Usage:
 *   php artisan guard:sync-roles
 *   $user->assignRole('super-admin');
 */
final class SuperAdminRole extends BaseRole
{
    #[Override]
    public function getLevel(): int
    {
        return 1000;
    }

    /** @return list<string> */
    #[Override]
    public function permissions(): array
    {
        return [PermissionKey::WILDCARD];
    }
}

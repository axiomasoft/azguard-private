<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Exceptions\AzGuardException;

final class RolePermissionConnectionException extends AzGuardException
{
    public static function mismatch(?string $roleConnection, ?string $permissionConnection): self
    {
        return new self(sprintf(
            'Role and role-permission models use different database connections (%s vs %s).',
            $roleConnection ?? 'default',
            $permissionConnection ?? 'default',
        ));
    }
}

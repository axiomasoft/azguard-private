<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Models\Role;
use AzGuard\Permissions\PermissionKey;
use BackedEnum;

/**
 * Shared helper: resolve a Role model from a role class-string, a name string
 * or a Role instance.
 *
 * Used by HasAzGuard and HasScopedRoles to avoid duplicating the same
 * lookup logic.
 */
trait ResolvesRole
{
    /**
     * Resolve a Role model from a role class-string (preferred — unambiguous),
     * a name string, a backed enum (unwrapped via its ->value, B-04), or a
     * Role instance.
     *
     * Returns null when the role cannot be found in the database.
     *
     * @param  string|BackedEnum|Role|class-string<RoleInterface>  $role
     */
    protected function resolveRole(string|BackedEnum|Role $role): ?Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        if ($role instanceof BackedEnum) {
            $role = PermissionKey::normalize($role);
        }

        /** @var class-string<Role> $roleClass */
        $roleClass = Config::roleModel();

        // Class-strings resolve only by exact class_name. A missing class row
        // is never adopted via getName() / a same-named DB-only role.
        if (is_subclass_of($role, RoleInterface::class)) {
            return $roleClass::query()->where('class_name', $role)->first();
        }

        return $roleClass::findByName($role);
    }
}

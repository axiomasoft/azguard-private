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

        // A role class-string (e.g. App\Guards\App\Roles\EditorRole) resolves by
        // its stored class_name, then falls back to the name derived from the
        // class — so assignRole(EditorRole::class) is unambiguous and refactor-safe.
        if (is_subclass_of($role, RoleInterface::class)) {
            return $roleClass::query()->where('class_name', $role)->first()
                ?? $roleClass::findByName((new $role)->getName());
        }

        return $roleClass::findByName($role);
    }
}

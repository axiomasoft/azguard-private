<?php

declare(strict_types=1);

namespace AzGuard\Support;

use AzGuard\Contracts\RoleInterface;
use AzGuard\Exceptions\InvalidRoleClassException;
use AzGuard\Exceptions\InvalidRoleIdentityException;
use AzGuard\Roles\SuperAdminRole;

/**
 * Canonical persisted name and checked class resolution for code roles.
 *
 * @internal
 */
final class RoleIdentity
{
    public const SUPER_ADMIN_NAME = 'super-admin';

    public const NAME_MAX_LENGTH = 255;

    public static function isBuiltInSuperAdmin(string $className): bool
    {
        return $className === SuperAdminRole::class;
    }

    /**
     * @param  class-string  $className
     */
    public static function persistedName(string $panelId, string $roleName, string $className): string
    {
        if (self::isBuiltInSuperAdmin($className)) {
            return self::SUPER_ADMIN_NAME;
        }

        self::assertComponent($panelId, 'panel id');
        self::assertComponent($roleName, 'name');

        $name = $panelId.':'.$roleName;

        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
            throw InvalidRoleIdentityException::tooLong($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }

    public static function logicOrFail(string $className): RoleInterface
    {
        if (! class_exists($className)) {
            throw InvalidRoleClassException::missing($className);
        }

        if (! is_subclass_of($className, RoleInterface::class)) {
            throw InvalidRoleClassException::notARole($className);
        }

        return new $className;
    }

    private static function assertComponent(string $value, string $component): void
    {
        if ($value === '') {
            throw InvalidRoleIdentityException::emptyComponent($component);
        }

        if (str_contains($value, ':')) {
            throw InvalidRoleIdentityException::containsSeparator($component, $value);
        }
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use AzGuard\Contracts\Permission;
use AzGuard\Exceptions\InvalidPermissionSyntaxException;
use UnitEnum;

/**
 * Internal permission-key grammar: strings, enums and Permission classes share
 * one syntax boundary. Documented global `*`, whole-segment `*`/`**`, and
 * `{name}` placeholders stay legal; empty, whitespace, empty-dot and partial
 * wildcard forms do not.
 *
 * @internal
 */
final class PermissionGrammar
{
    private function __construct() {}

    public static function raw(string|UnitEnum $permission): string
    {
        if ($permission instanceof UnitEnum) {
            return PermissionKey::normalize($permission);
        }

        if (self::isPermissionClass($permission)) {
            return $permission::ability();
        }

        return $permission;
    }

    public static function isPermissionClass(string $permission): bool
    {
        return ! str_contains($permission, PermissionKey::SEPARATOR)
            && is_subclass_of($permission, Permission::class);
    }

    public static function assertValid(string $key): void
    {
        if (! self::isValid($key)) {
            throw InvalidPermissionSyntaxException::forKey($key);
        }
    }

    public static function isValid(string $key): bool
    {
        if ($key === PermissionKey::WILDCARD) {
            return true;
        }

        if ($key === '' || preg_match('/\s/', $key) === 1) {
            return false;
        }

        $segments = explode(PermissionKey::SEPARATOR, $key);

        foreach ($segments as $segment) {
            if (! self::isValidSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidSegment(string $segment): bool
    {
        if (in_array($segment, ['', PermissionKey::WILDCARD, '**'], true)) {
            return $segment !== '';
        }

        if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
            $name = substr($segment, 1, -1);

            return $name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
        }

        return ! str_contains($segment, '*')
            && ! str_contains($segment, '{')
            && ! str_contains($segment, '}');
    }
}

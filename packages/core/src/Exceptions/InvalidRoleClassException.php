<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when a persisted code-role `class_name` is non-null but missing or
 * does not implement RoleInterface.
 */
final class InvalidRoleClassException extends AzGuardException
{
    public static function missing(string $className): self
    {
        return new self(sprintf(
            'Role class [%s] does not exist. Repair class_name or remove the stale row; a name-only match is not adopted.',
            $className,
        ));
    }

    public static function notARole(string $className): self
    {
        return new self(sprintf(
            'Role class [%s] does not implement AzGuard\\Contracts\\RoleInterface.',
            $className,
        ));
    }
}

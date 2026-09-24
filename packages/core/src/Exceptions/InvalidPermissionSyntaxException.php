<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when a permission key fails the unified AzGuard grammar.
 */
final class InvalidPermissionSyntaxException extends AzGuardException
{
    public static function forKey(string $key): self
    {
        return new self("Permission key [{$key}] is not valid AzGuard grammar.");
    }
}

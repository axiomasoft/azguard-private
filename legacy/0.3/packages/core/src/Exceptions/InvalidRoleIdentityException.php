<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when a code-role persisted name cannot be derived safely.
 */
final class InvalidRoleIdentityException extends AzGuardException
{
    public static function emptyComponent(string $component): self
    {
        return new self(sprintf('Code-role %s must be a non-empty string.', $component));
    }

    public static function containsSeparator(string $component, string $value): self
    {
        return new self(sprintf(
            'Code-role %s [%s] must not contain ":".',
            $component,
            $value,
        ));
    }

    public static function tooLong(string $name, int $max): self
    {
        return new self(sprintf(
            'Code-role persisted name [%s] exceeds the %d-character roles.name column.',
            $name,
            $max,
        ));
    }
}

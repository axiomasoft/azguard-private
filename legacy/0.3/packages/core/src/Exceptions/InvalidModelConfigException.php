<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when az-guard.models.* does not name an existing subclass of the
 * documented AzGuard base model.
 */
final class InvalidModelConfigException extends AzGuardException
{
    public static function forKey(string $configKey, mixed $received, string $expectedBase): self
    {
        $receivedLabel = is_string($received) ? $received : get_debug_type($received);

        return new self(sprintf(
            'Invalid az-guard.%s [%s]: expected an existing subclass of %s.',
            $configKey,
            $receivedLabel,
            $expectedBase,
        ));
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The package configuration fails a load-time check.
 */
final class InvalidConfigurationException extends ConfigurationException
{
    public function code(): string
    {
        return 'invalid_configuration';
    }
}

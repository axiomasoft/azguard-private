<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The panel registry or a compiled panel builder is changed after the application has booted.
 */
final class RegistryFrozenException extends DefinitionException
{
    public function code(): string
    {
        return 'registry_frozen';
    }
}

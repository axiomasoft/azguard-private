<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The panel registry or a compiled panel builder is changed after the application has booted.
 */
#[Api(since: '1.0')]
final class RegistryFrozenException extends DefinitionException
{
    public function code(): string
    {
        return 'registry_frozen';
    }
}

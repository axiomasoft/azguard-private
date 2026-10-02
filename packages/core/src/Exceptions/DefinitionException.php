<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A panel, source, role or plugin is declared in a way the package cannot build.
 */
class DefinitionException extends AzGuardException
{
    public function code(): string
    {
        return 'definition';
    }
}

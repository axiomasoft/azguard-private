<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A panel, source, role or plugin is declared in a way the package cannot build.
 */
#[Api(since: '1.0')]
class DefinitionException extends AzGuardException
{
    public function code(): string
    {
        return 'definition';
    }
}

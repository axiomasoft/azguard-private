<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Two panels resolve to the same permission name prefix.
 */
final class PrefixConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'prefix_conflict';
    }
}

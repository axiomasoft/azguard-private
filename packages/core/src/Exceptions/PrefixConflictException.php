<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Two panels resolve to the same permission name prefix.
 */
#[Api(since: '1.0')]
final class PrefixConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'prefix_conflict';
    }
}

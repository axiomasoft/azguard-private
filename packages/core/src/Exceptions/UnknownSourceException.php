<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A panel names a source that is not registered.
 */
#[Api(since: '1.0')]
final class UnknownSourceException extends DefinitionException
{
    public function code(): string
    {
        return 'unknown_source';
    }
}

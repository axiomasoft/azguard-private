<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A panel has more than one source that stores grants.
 */
#[Api(since: '0.7')]
final class WriterConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'writer_conflict';
    }
}

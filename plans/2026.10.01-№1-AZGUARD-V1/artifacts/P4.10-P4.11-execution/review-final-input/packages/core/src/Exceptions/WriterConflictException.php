<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A panel has more than one source that stores grants.
 */
final class WriterConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'writer_conflict';
    }
}

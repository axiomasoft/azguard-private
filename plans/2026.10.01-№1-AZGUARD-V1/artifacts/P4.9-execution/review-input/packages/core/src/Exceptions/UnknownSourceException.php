<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A panel names a source that is not registered.
 */
final class UnknownSourceException extends DefinitionException
{
    public function code(): string
    {
        return 'unknown_source';
    }
}

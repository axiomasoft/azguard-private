<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Two sources of a panel contribute different definitions under one permission key.
 */
final class DuplicatePermissionException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_permission';
    }
}

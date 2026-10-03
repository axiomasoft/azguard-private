<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Two role classes of a panel share a key, or a former key of one role is a key of another.
 */
final class DuplicateRoleException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_role';
    }
}

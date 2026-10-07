<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The role is neither a code role of the panel nor a key it can be granted under; a former key is not an alias.
 */
final class UnknownRoleException extends ChangeException
{
    public function code(): string
    {
        return 'unknown_role';
    }
}

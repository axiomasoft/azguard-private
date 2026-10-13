<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Two role classes of a panel share a key, or a former key of one role is a key of another.
 */
#[Api(since: '1.0')]
final class DuplicateRoleException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_role';
    }
}

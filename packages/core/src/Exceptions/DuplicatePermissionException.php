<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Two sources of a panel contribute different definitions under one permission key.
 */
#[Api(since: '0.7')]
final class DuplicatePermissionException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_permission';
    }
}

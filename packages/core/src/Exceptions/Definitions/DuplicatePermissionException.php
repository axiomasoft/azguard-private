<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Definitions;

use Packman\Attributes\Api;

/**
 * Two sources of a panel contribute different definitions under one permission key.
 */
#[Api(since: '1.0')]
final class DuplicatePermissionException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_permission';
    }
}

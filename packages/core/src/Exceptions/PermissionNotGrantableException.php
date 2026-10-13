<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The permission is decided by its policy alone and never receives an exact assignment.
 */
#[Api(since: '1.0')]
final class PermissionNotGrantableException extends ChangeException
{
    public function code(): string
    {
        return 'permission_not_grantable';
    }
}

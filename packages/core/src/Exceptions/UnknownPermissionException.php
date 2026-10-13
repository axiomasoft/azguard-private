<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The permission is not defined in any panel it could belong to.
 */
#[Api(since: '1.0')]
final class UnknownPermissionException extends ChangeException
{
    public function code(): string
    {
        return 'unknown_permission';
    }
}

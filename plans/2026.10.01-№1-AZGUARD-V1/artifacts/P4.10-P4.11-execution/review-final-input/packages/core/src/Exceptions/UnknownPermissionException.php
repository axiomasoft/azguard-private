<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The permission is not defined in any panel it could belong to.
 */
final class UnknownPermissionException extends ChangeException
{
    public function code(): string
    {
        return 'unknown_permission';
    }
}

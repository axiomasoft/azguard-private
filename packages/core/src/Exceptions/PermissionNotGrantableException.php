<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The permission is decided by its policy alone and never receives an exact assignment.
 */
final class PermissionNotGrantableException extends ChangeException
{
    public function code(): string
    {
        return 'permission_not_grantable';
    }
}

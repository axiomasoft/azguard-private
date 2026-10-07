<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The code role exists but is not granted through storage.
 */
final class RoleNotGrantableException extends ChangeException
{
    public function code(): string
    {
        return 'role_not_grantable';
    }
}

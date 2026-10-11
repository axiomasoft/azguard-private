<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The code role exists but is not granted through storage.
 */
#[Api(since: '0.7')]
final class RoleNotGrantableException extends ChangeException
{
    public function code(): string
    {
        return 'role_not_grantable';
    }
}

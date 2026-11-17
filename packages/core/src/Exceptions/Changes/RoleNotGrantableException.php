<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Changes;

use Packman\Attributes\Api;

/**
 * The code role exists but is not granted through storage.
 */
#[Api(since: '1.0')]
final class RoleNotGrantableException extends ChangeException
{
    public function code(): string
    {
        return 'role_not_grantable';
    }
}

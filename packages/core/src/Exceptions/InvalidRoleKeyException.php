<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

#[Api(since: '0.7')]
final class InvalidRoleKeyException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_role_key';
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class InvalidRoleKeyException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_role_key';
    }
}

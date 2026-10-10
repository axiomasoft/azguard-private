<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class InvalidPermissionKeyException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_permission_key';
    }
}

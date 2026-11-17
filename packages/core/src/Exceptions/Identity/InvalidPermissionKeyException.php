<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Identity;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class InvalidPermissionKeyException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_permission_key';
    }
}

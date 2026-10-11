<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

#[Api(since: '0.7')]
final class InvalidAssignmentScopeException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_context';
    }
}

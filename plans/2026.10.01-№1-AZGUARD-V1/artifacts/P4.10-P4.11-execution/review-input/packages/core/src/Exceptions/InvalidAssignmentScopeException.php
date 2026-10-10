<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class InvalidAssignmentScopeException extends InvalidIdentityException
{
    public function code(): string
    {
        return 'invalid_context';
    }
}

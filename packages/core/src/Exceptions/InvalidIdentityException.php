<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A key, id or reference violates the identity grammar.
 */
#[Api(since: '0.7')]
class InvalidIdentityException extends AzGuardException
{
    public function code(): string
    {
        return 'invalid_identity';
    }
}

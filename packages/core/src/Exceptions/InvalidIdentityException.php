<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A key, id or reference violates the identity grammar.
 */
class InvalidIdentityException extends AzGuardException
{
    public function code(): string
    {
        return 'invalid_identity';
    }
}

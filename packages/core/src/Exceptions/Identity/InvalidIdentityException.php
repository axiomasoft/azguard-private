<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Identity;

use AzGuard\Exceptions\AzGuardException;
use Packman\Attributes\Api;

/**
 * A key, id or reference violates the identity grammar.
 */
#[Api(since: '1.0')]
class InvalidIdentityException extends AzGuardException
{
    public function code(): string
    {
        return 'invalid_identity';
    }
}

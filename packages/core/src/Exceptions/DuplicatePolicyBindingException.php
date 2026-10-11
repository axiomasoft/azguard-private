<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * One permission is bound to two different policies.
 */
#[Api(since: '0.7')]
final class DuplicatePolicyBindingException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_policy_binding';
    }
}

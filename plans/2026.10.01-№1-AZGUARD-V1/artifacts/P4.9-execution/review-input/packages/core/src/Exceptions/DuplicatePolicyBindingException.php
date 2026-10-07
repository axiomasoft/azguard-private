<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * One permission is bound to two different policies.
 */
final class DuplicatePolicyBindingException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_policy_binding';
    }
}

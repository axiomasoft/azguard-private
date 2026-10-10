<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A policy-only permission has no policy binding, or a binding does not fit the permission.
 */
final class InvalidPolicyStructureException extends DefinitionException
{
    public function code(): string
    {
        return 'invalid_policy_structure';
    }
}

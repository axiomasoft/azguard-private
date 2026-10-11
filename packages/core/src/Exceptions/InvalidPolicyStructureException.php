<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A policy-only permission has no policy binding, or a binding does not fit the permission.
 */
#[Api(since: '0.7')]
final class InvalidPolicyStructureException extends DefinitionException
{
    public function code(): string
    {
        return 'invalid_policy_structure';
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Definitions;

use Packman\Attributes\Api;

/**
 * One permission is bound to two different policies.
 */
#[Api(since: '1.0')]
final class DuplicatePolicyBindingException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_policy_binding';
    }
}

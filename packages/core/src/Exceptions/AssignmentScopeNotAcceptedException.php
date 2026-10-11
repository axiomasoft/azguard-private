<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The assignment scope is not accepted for this role, target or tenant.
 */
#[Api(since: '0.7')]
final class AssignmentScopeNotAcceptedException extends ChangeException
{
    public function code(): string
    {
        return 'context_not_accepted';
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The role is granted only inside an assignment scope.
 */
#[Api(since: '0.7')]
final class AssignmentScopeRequiredException extends ChangeException
{
    public function code(): string
    {
        return 'context_required';
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The role is granted only inside an assignment scope.
 */
final class AssignmentScopeRequiredException extends ChangeException
{
    public function code(): string
    {
        return 'context_required';
    }
}

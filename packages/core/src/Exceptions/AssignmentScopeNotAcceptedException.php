<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The assignment scope is not accepted for this role, target or tenant.
 */
final class AssignmentScopeNotAcceptedException extends ChangeException
{
    public function code(): string
    {
        return 'context_not_accepted';
    }
}

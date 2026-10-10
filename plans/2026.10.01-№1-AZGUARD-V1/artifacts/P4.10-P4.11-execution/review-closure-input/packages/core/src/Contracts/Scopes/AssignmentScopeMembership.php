<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Tells whether a subject belongs to an assignment scope.
 *
 * @spi
 */
interface AssignmentScopeMembership
{
    public function isMember(SubjectRef $subject, AssignmentScopeRef $context): bool;
}

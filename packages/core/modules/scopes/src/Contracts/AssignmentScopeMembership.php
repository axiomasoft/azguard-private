<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Tells whether a subject belongs to an assignment scope.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeMembership
{
    public function isMember(SubjectRef $subject, AssignmentScopeRef $context): bool;
}

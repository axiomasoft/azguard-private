<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;

final readonly class ContextMembership implements AssignmentScopeMembership
{
    public function __construct(private Membership $membership) {}

    public function isMember(SubjectRef $subject, AssignmentScopeRef $context): bool
    {
        return $this->membership->isMember($subject, $context);
    }
}

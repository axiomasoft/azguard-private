<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use RuntimeException;

final class Membership implements TenantMembership
{
    public int $checks = 0;

    public function __construct(public array $members = ['1'], public bool $throws = false) {}

    public function isMember(SubjectRef $subject, TenantRef|AssignmentScopeRef $tenant): bool
    {
        $this->checks++;

        if ($this->throws) {
            throw new RuntimeException('membership unavailable');
        }

        return in_array($subject->id(), $this->members, true);
    }
}

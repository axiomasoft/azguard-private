<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

/** Every subject belongs to every tenant of the fixture. */
final class EveryoneMember implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool
    {
        return true;
    }
}

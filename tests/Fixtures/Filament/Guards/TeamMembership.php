<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

final class TeamMembership implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool
    {
        return true;
    }
}

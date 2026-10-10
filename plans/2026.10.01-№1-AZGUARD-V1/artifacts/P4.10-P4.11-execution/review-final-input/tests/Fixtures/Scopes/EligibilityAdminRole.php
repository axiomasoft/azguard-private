<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;

#[Role('root')]
#[SuperAdmin]
final class EligibilityAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }

    public function scopes(): array
    {
        return [EligibilityProjectScope::make()->filter(EligibilityCityFilter::class)];
    }

    public function scopeRequired(): bool
    {
        return true;
    }
}

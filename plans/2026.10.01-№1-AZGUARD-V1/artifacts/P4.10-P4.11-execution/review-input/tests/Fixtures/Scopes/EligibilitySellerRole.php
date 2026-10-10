<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('seller')]
final class EligibilitySellerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
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

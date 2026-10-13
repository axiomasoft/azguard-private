<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('analyst')]
final class EligibilityAnalystRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [EligibilityProjectScope::class];
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;

#[Role('root')]
#[SuperAdmin]
final class RootRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

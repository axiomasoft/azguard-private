<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;

#[Role('super', label: 'Super')]
#[SuperAdmin]
final class SuperRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

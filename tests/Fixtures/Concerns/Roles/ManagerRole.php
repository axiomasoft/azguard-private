<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Concerns\AdminPermission;

#[Role('manager')]
final class ManagerRole extends BaseRole
{
    public function permissions(): array
    {
        return [AdminPermission::View, AdminPermission::Update];
    }
}

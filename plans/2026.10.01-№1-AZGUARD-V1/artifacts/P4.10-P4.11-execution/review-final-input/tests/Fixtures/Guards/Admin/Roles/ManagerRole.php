<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Orders\OrderPermission;

#[Role('manager', label: 'Manager', level: 10)]
final class ManagerRole extends BaseRole
{
    public function permissions(): array
    {
        return [OrderPermission::View];
    }
}

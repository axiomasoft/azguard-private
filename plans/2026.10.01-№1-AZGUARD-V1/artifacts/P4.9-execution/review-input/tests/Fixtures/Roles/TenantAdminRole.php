<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;

#[Role('tenant-admin')]
#[SuperAdmin]
final class TenantAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }

    public function scopes(): array
    {
        return [StoreScope::class];
    }
}

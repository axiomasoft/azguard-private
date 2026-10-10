<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;

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
        return [ProjectScope::make()->filter(new SellerProjects)];
    }

    public function scopeRequired(): bool
    {
        return true;
    }
}

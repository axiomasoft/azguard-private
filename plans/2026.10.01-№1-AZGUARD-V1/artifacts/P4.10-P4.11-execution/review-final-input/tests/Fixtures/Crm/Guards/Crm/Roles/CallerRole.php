<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;

#[Role('caller')]
final class CallerRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::View, ClientPermission::Update];
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

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\AnalystProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;

#[Role('analyst')]
final class AnalystRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::View, ClientPermission::ViewAny];
    }

    public function scopes(): array
    {
        return [ProjectScope::make()->filter(new AnalystProjects)];
    }

    public function scopeRequired(): bool
    {
        return true;
    }
}

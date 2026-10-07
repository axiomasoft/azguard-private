<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;

/** Allowed in the global tenant of a tenant panel through allowGlobalRoles(). */
#[Role('support')]
final class SupportRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::ViewAny];
    }
}

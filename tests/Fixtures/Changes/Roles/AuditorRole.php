<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes\Roles;

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;

#[Role('auditor')]
#[FormerKeys('inspector')]
final class AuditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::View];
    }
}

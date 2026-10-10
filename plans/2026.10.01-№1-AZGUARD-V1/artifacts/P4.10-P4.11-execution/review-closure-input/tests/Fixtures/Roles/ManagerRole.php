<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/**
 * Manager.
 */
#[Role('manager')]
final class ManagerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['clients.view'];
    }
}

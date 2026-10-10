<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/**
 * A second class with the key of ManagerRole.
 */
#[Role('manager')]
final class OtherManagerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['clients.view'];
    }
}

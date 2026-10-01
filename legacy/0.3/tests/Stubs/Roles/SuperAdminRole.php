<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Roles\BaseRole;

class SuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return ['*'];
    }
}

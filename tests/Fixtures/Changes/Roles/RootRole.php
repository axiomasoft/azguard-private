<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes\Roles;

use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('root')]
#[NotGrantable]
final class RootRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

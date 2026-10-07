<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** A role granted tenant-wide only: it binds no assignment scope type. */
#[Role('unbound')]
final class UnboundRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

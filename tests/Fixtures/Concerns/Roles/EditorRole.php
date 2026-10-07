<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Concerns\CabinetPermission;

/** Registered in the cabinet panel only. */
#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [CabinetPermission::View];
    }
}

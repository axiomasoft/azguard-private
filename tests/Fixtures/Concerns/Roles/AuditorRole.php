<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** Registered in both panels: a role class of two panels names no single panel. */
#[Role('auditor')]
final class AuditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

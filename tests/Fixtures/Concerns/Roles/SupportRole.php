<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('support')]
final class SupportRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.*'];
    }
}

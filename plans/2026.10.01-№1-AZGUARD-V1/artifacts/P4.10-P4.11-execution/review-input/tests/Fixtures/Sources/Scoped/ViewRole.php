<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\Role;

#[Role('orders')]
final class ViewRole extends OrderRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }
}

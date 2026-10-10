<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;

#[Role('orders')]
class OrderRole extends BaseRole
{
    public function permissions(): array
    {
        return [OrderPermission::View, 'orders.*'];
    }

    public function scopes(): array
    {
        return [StoreScope::class];
    }
}

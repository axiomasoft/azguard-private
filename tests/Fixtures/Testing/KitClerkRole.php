<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** An ordinary role of the kit panel: it cancels orders and admits to the panel. */
#[Role('clerk')]
final class KitClerkRole extends BaseRole
{
    public function permissions(): array
    {
        return [OrdersPermission::Cancel];
    }
}

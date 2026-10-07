<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum AdminPermission: string
{
    case View = 'orders.view';
    case Update = 'orders.update';
    case Refund = 'orders.refund';
    case UsersDelete = 'users.delete';
    #[PolicyOnly]
    case Profile = 'profile.view';
}

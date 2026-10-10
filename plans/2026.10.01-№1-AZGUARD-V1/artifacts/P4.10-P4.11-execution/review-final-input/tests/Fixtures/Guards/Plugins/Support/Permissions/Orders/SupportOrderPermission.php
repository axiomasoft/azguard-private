<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Support\Permissions\Orders;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum SupportOrderPermission: string
{
    case View = 'support.orders.view';
}

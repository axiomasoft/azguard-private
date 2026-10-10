<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Desk\Permissions\Orders;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum DeskOrderPermission: string
{
    case View = 'desk.orders.view';
}

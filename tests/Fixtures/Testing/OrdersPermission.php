<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum OrdersPermission: string
{
    case Cancel = 'orders.cancel';
    case View = 'orders.view';
}

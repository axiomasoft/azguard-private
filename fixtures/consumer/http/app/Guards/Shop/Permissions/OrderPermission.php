<?php

declare(strict_types=1);

namespace App\Guards\Shop\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum OrderPermission: string
{
    case View = 'orders.view';
    case Refund = 'orders.refund';
}

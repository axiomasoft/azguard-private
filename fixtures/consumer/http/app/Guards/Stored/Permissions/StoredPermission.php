<?php

declare(strict_types=1);

namespace App\Guards\Stored\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum StoredPermission: string
{
    case Refund = 'orders.refund';
}

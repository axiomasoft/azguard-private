<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum OrderPermission: string
{
    case View = 'orders.view';
    case Update = 'orders.update';
}

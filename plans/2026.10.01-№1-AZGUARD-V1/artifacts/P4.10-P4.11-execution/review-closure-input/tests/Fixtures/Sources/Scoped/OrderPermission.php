<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum OrderPermission: string
{
    case View = 'orders.view';
    case Export = 'orders.export';
    case Other = 'other.view';
}

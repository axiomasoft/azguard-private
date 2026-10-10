<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Ambiguous\Permissions\Orders;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum TwoPermission: string
{
    case View = 'ambiguous.two.view';
}

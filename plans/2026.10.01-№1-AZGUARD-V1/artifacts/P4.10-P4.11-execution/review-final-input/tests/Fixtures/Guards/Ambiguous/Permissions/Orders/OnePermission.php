<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Ambiguous\Permissions\Orders;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum OnePermission: string
{
    case View = 'ambiguous.one.view';
}

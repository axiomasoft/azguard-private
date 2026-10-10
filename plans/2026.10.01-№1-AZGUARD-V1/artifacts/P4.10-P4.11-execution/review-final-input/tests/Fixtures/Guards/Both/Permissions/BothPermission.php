<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Both\Permissions;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
#[PolicyOnly]
enum BothPermission: string
{
    case View = 'both.view';
}

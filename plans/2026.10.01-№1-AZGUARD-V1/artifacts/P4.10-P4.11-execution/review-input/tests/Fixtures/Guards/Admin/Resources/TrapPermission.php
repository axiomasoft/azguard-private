<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Resources;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum TrapPermission: string
{
    case View = 'resources.trap.view';
}

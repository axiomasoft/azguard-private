<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Duo;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum FirstPermission: string
{
    case View = 'duo.first.view';
}

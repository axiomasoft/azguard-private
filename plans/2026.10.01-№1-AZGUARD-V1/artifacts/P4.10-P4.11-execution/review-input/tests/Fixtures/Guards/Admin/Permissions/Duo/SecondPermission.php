<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Duo;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum SecondPermission: string
{
    case View = 'duo.second.view';
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Users;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum UserPermission: string
{
    case View = 'users.view';
}

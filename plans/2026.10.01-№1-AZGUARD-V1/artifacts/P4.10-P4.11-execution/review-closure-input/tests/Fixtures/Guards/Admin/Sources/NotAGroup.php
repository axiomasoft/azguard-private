<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Sources;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum NotAGroup: string
{
    case View = 'sources.trap.view';
}

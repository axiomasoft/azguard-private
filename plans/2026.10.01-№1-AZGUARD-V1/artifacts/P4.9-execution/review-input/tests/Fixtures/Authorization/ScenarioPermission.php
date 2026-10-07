<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ScenarioPermission: string
{
    case View = 'orders.present';
}

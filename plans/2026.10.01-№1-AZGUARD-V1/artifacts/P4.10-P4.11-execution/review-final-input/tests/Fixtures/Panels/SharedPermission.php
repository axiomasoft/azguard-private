<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Permissions\RequiresGrant;

/**
 * A permission enum the tests attach to two panels.
 */
#[RequiresGrant]
enum SharedPermission: string
{
    case Export = 'reports.export';
}

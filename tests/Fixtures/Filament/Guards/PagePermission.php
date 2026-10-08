<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** The page of the fixture Filament panels, with Enums definitions. */
#[RequiresGrant]
enum PagePermission: string
{
    case Probe = 'pages.probe';
}

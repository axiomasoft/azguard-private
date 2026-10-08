<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** The second resource of the order model, decided by grants. */
#[RequiresGrant]
enum ArchivedOrderPermission: string
{
    case ViewAny = 'archived-orders.view_any';
    case View = 'archived-orders.view';
}

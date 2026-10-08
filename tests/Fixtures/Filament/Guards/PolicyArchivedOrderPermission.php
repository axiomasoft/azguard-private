<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\PolicyOnly;

/** The second resource of the order model, decided by its policy alone. */
#[PolicyOnly]
enum PolicyArchivedOrderPermission: string
{
    case ViewAny = 'archived-orders.view_any';
    case View = 'archived-orders.view';
}

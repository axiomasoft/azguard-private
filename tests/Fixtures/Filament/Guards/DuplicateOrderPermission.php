<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** Owns a key that FilamentSource defines too. */
#[RequiresGrant]
enum DuplicateOrderPermission: string
{
    case ViewAny = 'orders.view_any';
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum EntryPermission: string
{
    case Enter = 'entry.enter';
}

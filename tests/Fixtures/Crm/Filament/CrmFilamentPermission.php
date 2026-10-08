<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Permissions\RequiresGrant;

/** What the CRM Filament panel needs beyond the client permissions of the stand. */
#[RequiresGrant]
enum CrmFilamentPermission: string
{
    case Delete = 'clients.delete';
    case DeleteAny = 'clients.delete_any';
    case ClientCount = 'widgets.client-count';
}

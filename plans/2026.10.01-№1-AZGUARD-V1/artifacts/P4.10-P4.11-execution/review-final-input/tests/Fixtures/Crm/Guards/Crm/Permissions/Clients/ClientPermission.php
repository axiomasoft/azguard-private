<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;
use AzGuard\Tests\Fixtures\Crm\Models\Client;

#[RequiresGrant]
#[Resource(label: 'Клиенты', model: Client::class)]
enum ClientPermission: string
{
    case View = 'clients.view';
    case Update = 'clients.update';
    case ViewAny = 'clients.view_any';
    #[PolicyOnly]
    case ViewOwnProfile = 'clients.view_own_profile';
}

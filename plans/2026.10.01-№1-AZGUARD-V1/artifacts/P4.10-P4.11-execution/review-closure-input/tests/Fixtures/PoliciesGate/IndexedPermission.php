<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[Resource(model: GateRecord::class)]
#[RequiresGrant]
enum IndexedPermission: string
{
    case ViewAny = 'inventory.view_any';
    case Update = 'inventory.update';
    case FirstRead = 'inventory.read';
    case SecondRead = 'archive.read';
}

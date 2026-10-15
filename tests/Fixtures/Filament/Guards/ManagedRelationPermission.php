<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** The ability that `ManagedProductsRelationManager` declares for changing the products of an order. */
#[RequiresGrant]
enum ManagedRelationPermission: string
{
    case ManageProducts = 'orders.manage_products';
}

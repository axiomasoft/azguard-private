<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** Permissions of the resources, page and widget that the authorization tests register in the admin panel. */
#[RequiresGrant]
enum OrderPermission: string
{
    case ViewAny = 'orders.view_any';
    case View = 'orders.view';
    case Update = 'orders.update';
    case Delete = 'orders.delete';
    case DeleteAny = 'orders.delete_any';
    case ProductsViewAny = 'products.view_any';
    case ProductsView = 'products.view';
    case Reports = 'pages.reports';
    case OrderCount = 'widgets.order-count';
}

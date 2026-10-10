<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Permissions;

use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ShopPermission: string
{
    #[GrantedToAll]
    case Browse = 'shop.browse';
    case Sell = 'shop.sell';
    case Edit = 'shop.edit';
}

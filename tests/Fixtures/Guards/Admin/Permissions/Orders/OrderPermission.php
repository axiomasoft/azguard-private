<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Orders;

use AzGuard\Permissions\Describe;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;
use AzGuard\Tests\Fixtures\Panels\User;

#[Resource(label: 'Orders', model: User::class)]
#[RequiresGrant]
enum OrderPermission: string
{
    #[Describe('View list')]
    #[GrantedToAll]
    case View = 'orders.view';

    #[Describe('Refund', group: 'refunds', description: 'Money back')]
    #[PolicyOnly]
    case Refund = 'orders.refund';

    #[Describe('Line refund')]
    case LineRefund = 'orders.line.refund';
}

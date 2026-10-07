<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders\Permissions;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;
use AzGuard\Tests\Fixtures\Guards\Orders\Order;

#[RequiresGrant]
#[Resource(model: Order::class)]
enum OrderPermission: string
{
    case Refund = 'orders.refund';
    #[PolicyOnly] case ViewOwn = 'orders.view_own';
    #[PolicyOnly] case ViewAny = 'orders.view_any';
    #[PolicyOnly] case Create = 'orders.create';
    #[PolicyOnly] case UserOnly = 'orders.user_only';
    #[PolicyOnly] case SameClass = 'orders.same_class';
    #[PolicyOnly] case Missing = 'orders.missing';
    #[PolicyOnly] case ResourceClass = 'orders.resource_class';
    #[PolicyOnly] case StringClass = 'orders.string_class';
    #[PolicyOnly] case Defaults = 'orders.defaults';
    #[PolicyOnly] case NativeBefore = 'orders.native_before';
    case Unbound = 'orders.unbound';
}

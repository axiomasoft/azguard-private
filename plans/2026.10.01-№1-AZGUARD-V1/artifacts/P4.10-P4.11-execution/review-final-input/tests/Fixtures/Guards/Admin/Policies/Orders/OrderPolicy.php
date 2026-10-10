<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Policies\Orders;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Orders\OrderPermission;

final class OrderPolicy
{
    #[Decides(OrderPermission::Refund)]
    public function anyName(): bool
    {
        return true;
    }

    #[Decides(OrderPermission::LineRefund)]
    public function line(): bool
    {
        return true;
    }

    public function ignored(): bool
    {
        return false;
    }
}

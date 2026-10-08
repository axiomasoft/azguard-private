<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Guards\Shop\Permissions\OrderPermission;
use AzGuard\Attributes\CheckPermission;

#[CheckPermission(OrderPermission::View, only: ['index'])]
final class ShopOrderController
{
    public function index(): string
    {
        return 'orders';
    }

    #[CheckPermission(OrderPermission::Refund)]
    public function refund(): string
    {
        return 'refunded';
    }
}

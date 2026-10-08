<?php

declare(strict_types=1);

use App\Http\Controllers\ShopOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'azguard.panel:shop'])->group(function (): void {
    Route::get('/shop/orders', [ShopOrderController::class, 'index']);
    Route::get('/shop/orders/refund', [ShopOrderController::class, 'refund']);
});

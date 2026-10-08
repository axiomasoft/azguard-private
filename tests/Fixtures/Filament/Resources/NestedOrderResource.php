<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;

/** A slug with a slash: a nested segment of the key. */
final class NestedOrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'shop/orders';
}

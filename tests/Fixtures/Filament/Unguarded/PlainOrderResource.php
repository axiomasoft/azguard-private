<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Unguarded;

use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;

/** A resource that Filament decides alone. */
final class PlainOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'plain-orders';
}

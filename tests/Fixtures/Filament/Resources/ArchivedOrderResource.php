<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;

/** A second resource of the model of OrderResource. */
final class ArchivedOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'archived-orders';
}

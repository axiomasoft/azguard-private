<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;

/** The segment comes from `$azguardKey`, not from the slug. */
final class KeyedOrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'Not A Segment';

    protected static ?string $azguardKey = 'keyed-orders';
}

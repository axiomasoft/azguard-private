<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Unguarded;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/** Uses the trait but replaces its query, which would drop the visible records. */
final class QueryOrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'query-orders';

    public static function getEloquentQuery(): Builder
    {
        return Order::query();
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListArchivedOrders;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A second resource of the model of OrderResource. */
final class ArchivedOrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'archived-orders';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('number')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListArchivedOrders::route('/')];
    }
}

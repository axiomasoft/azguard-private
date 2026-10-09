<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Exports\OrderExporter;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'number';

    public static function table(Table $table): Table
    {
        return $table->columns(FilamentFixture::$tableColumns ?: [TextColumn::make('number')->searchable()])
            ->recordActions(FilamentFixture::$recordActions)
            ->headerActions([ExportAction::make()->exporter(OrderExporter::class)])
            ->toolbarActions(FilamentFixture::$toolbarActions ?: [DeleteBulkAction::make(), ExportBulkAction::make()->exporter(OrderExporter::class)]);
    }

    public static function getRelations(): array
    {
        return FilamentFixture::$relations ?: [ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}

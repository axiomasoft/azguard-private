<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Filament\Exports\OrderExporter;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Order::class;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    /**
     * Factories of the columns and actions that a test adds to the table, forgotten by `GateWorld::prepare()`.
     *
     * @var array{columns: list<Closure(): Column>, recordActions: list<Closure(): Action>, toolbarActions: list<Closure(): BulkAction>}
     */
    public static array $extras = ['columns' => [], 'recordActions' => [], 'toolbarActions' => []];

    protected static ?string $recordTitleAttribute = 'number';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('number')->searchable(), ...array_map(static fn (Closure $column): Column => $column(), self::$extras['columns'])])
            ->recordActions(array_map(static fn (Closure $action): Action => $action(), self::$extras['recordActions']))
            ->headerActions([ExportAction::make()->exporter(OrderExporter::class)])
            ->toolbarActions([DeleteBulkAction::make(), ExportBulkAction::make()->exporter(OrderExporter::class), ...array_map(static fn (Closure $action): BulkAction => $action(), self::$extras['toolbarActions'])]);
    }

    public static function getRelations(): array
    {
        return [ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}

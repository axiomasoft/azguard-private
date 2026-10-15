<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\RelationManagers;

use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\ProductResource;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The products of an order, changed with an ability of their own instead of `update` of the order. */
final class ManagedProductsRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static string $relationship = 'products';

    protected static ?string $azguardResource = ProductResource::class;

    protected static ?string $azguardRelationAbility = 'manage_products';

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')
            ->columns([TextColumn::make('name')])
            ->headerActions([AttachAction::make()->preloadRecordSelect()])
            ->recordActions([DetachAction::make()]);
    }
}

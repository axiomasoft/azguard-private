<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\RelationManagers;

use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\ProductResource;
use Filament\Actions\AttachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Products of an order, decided by the permissions of ProductResource. */
final class ProductsRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static string $relationship = 'products';

    protected static ?string $azguardResource = ProductResource::class;

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')
            ->columns([TextColumn::make('name')])
            ->headerActions([AttachAction::make()->preloadRecordSelect()]);
    }
}

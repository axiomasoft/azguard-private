<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources\Pages;

use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\ProductResource;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The products of an order on a page of their own, not in a relation manager. */
final class ManageOrderProducts extends ManageRelatedRecords
{
    protected static string $resource = OrderResource::class;

    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')
            ->columns([TextColumn::make('name')])
            ->headerActions([AttachAction::make()->preloadRecordSelect()])
            ->recordActions([DetachAction::make()])
            ->toolbarActions([DetachBulkAction::make()]);
    }
}

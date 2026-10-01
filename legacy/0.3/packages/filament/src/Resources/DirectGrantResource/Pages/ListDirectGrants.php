<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\DirectGrantResource\Pages;

use AzGuard\Filament\Resources\DirectGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

final class ListDirectGrants extends ListRecords
{
    protected static string $resource = DirectGrantResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Issue grant'),
        ];
    }
}

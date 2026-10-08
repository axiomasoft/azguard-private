<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Tests\Fixtures\Crm\Filament\Pages\ListClients;
use AzGuard\Tests\Fixtures\Crm\Filament\Pages\ViewClient;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Clients of the CRM in its Filament panel. Filament does not scope the query to the organization: the guard panel
 * keeps the clients of the tenant and of the projects the user may see.
 */
final class ClientResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Client::class;

    protected static ?string $slug = 'clients';

    protected static ?string $recordTitleAttribute = 'id';

    protected static bool $isScopedToTenant = false;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->searchable(), TextColumn::make('project_id')])
            ->headerActions([ExportAction::make()->exporter(ClientExporter::class)])
            ->toolbarActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListClients::route('/'), 'view' => ViewClient::route('/{record}')];
    }
}

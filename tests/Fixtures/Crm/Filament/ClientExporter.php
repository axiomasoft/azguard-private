<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class ClientExporter extends Exporter
{
    protected static ?string $model = Client::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Exported '.$export->successful_rows.' clients.';
    }
}

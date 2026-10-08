<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Exports;

use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

final class OrderExporter extends Exporter
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('number')];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Exported '.$export->successful_rows.' orders.';
    }
}

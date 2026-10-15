<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources\Pages;

use AzGuard\Tests\Fixtures\Filament\Resources\WidgetOrderResource;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\ProtectedWidget;
use Filament\Resources\Pages\ListRecords;

final class ListWidgetOrders extends ListRecords
{
    protected static string $resource = WidgetOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ProtectedWidget::class];
    }
}

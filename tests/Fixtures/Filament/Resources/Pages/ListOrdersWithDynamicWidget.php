<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources\Pages;

use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\DynamicWidget;
use Filament\Resources\Pages\ListRecords;

/** A page that returns a widget at run time, one that no key of the panel names. */
final class ListOrdersWithDynamicWidget extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [DynamicWidget::class];
    }
}

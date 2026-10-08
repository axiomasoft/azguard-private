<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources\Pages;

use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}

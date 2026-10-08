<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources\Pages;

use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListArchivedOrders extends ListRecords
{
    protected static string $resource = ArchivedOrderResource::class;
}

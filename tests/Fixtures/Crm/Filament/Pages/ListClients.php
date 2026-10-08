<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament\Pages;

use AzGuard\Tests\Fixtures\Crm\Filament\ClientResource;
use Filament\Resources\Pages\ListRecords;

final class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;
}

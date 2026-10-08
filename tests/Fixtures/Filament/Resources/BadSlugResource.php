<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Tests\Fixtures\Filament\Models\Order;
use Filament\Resources\Resource;

final class BadSlugResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'Not A Segment';
}

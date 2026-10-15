<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Resources;

use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListWidgetOrders;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\ProtectedWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\UnprotectedWidget;

/** Orders whose widgets are declared by the resource, not by the Filament panel. */
final class WidgetOrderResource extends OrderResource
{
    protected static ?string $slug = 'orders';

    public static bool $withUnprotected = true;

    public static function getWidgets(): array
    {
        return self::$withUnprotected ? [UnprotectedWidget::class, ProtectedWidget::class] : [ProtectedWidget::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListWidgetOrders::route('/')];
    }
}

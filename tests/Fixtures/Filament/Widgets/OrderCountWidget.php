<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/** Counts the orders through the query of their resource. */
final class OrderCountWidget extends Widget
{
    use AuthorizesWidget;

    protected static ?string $azguardKey = 'order-count';

    public function render(): View
    {
        return app(Factory::class)->file(__DIR__.'/../views/count.blade.php', ['count' => OrderResource::getEloquentQuery()->count()]);
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/** Counts the orders through the query of their resource. */
final class OrderCountWidget extends Widget
{
    use AuthorizesWidget;

    protected static ?string $azguardKey = 'order-count';

    public function deleteOrder(): void
    {
        $record = Order::query()->findOrFail(1);
        FilamentGate::resource(OrderResource::class, 'delete', $record)->authorize();
        $record->delete();
    }

    public function render(): View
    {
        return app(Factory::class)->file(__DIR__.'/../views/count.blade.php', ['count' => OrderResource::getEloquentQuery()->count()]);
    }
}

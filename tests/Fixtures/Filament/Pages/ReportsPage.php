<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Pages;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Pages\Page;

/** A page opened by its permission `pages.reports`. */
final class ReportsPage extends Page
{
    use AuthorizesPage;

    protected static ?string $slug = 'reports';

    protected string $view = 'azguard-fixtures::probe';

    public function deleteOrder(): void
    {
        $record = Order::query()->findOrFail(1);
        FilamentGate::resource(OrderResource::class, 'delete', $record)->authorize();
        $record->delete();
    }
}

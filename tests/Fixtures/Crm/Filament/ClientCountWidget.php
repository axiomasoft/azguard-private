<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/** Counts the clients through the query of their resource. */
final class ClientCountWidget extends Widget
{
    use AuthorizesWidget;

    protected static ?string $azguardKey = 'client-count';

    public function render(): View
    {
        return app(Factory::class)->file(__DIR__.'/../../Filament/views/count.blade.php', ['count' => ClientResource::getEloquentQuery()->count()]);
    }
}

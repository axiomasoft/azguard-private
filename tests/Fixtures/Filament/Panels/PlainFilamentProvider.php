<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Panels;

use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use Filament\Panel;
use Filament\PanelProvider;

/** A Filament panel at /plain without the AzGuard plugin: AzGuard takes no part in it. */
final class PlainFilamentProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('plain')->path('plain')->authGuard('web')->resources([OrderResource::class]);
    }
}

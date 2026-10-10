<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * A module that adjusts one panel and every panel through the facade, so the origins of those records can be read.
 */
final class AdjustsPanels extends ServiceProvider
{
    public function register(): void
    {
        AzGuard::configurePanel(
            config('blog.azguard_panel', 'admin'),
            static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Module label'),
        );
        AzGuard::configurePanels(
            static fn (PanelBuilder $panel): PanelBuilder => $panel->description('All panels'),
        );
    }
}

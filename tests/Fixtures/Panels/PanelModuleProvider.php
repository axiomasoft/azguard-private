<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use Illuminate\Support\ServiceProvider;

/**
 * A module of the application: adds to the admin panel and to every panel from its own `register()`.
 */
final class PanelModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $registry = $this->app->make(PanelRegistry::class);

        $registry->configure('admin', static fn (PanelBuilder $panel): PanelBuilder => $panel->roles([AnalystRole::class]));
        $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->roles([RootRole::class]));
    }
}

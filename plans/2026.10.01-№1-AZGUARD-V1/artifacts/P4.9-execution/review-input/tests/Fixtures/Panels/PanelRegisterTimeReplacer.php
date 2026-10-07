<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Panels\PanelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A module that swaps the provider of the admin panel from `register()`, before the core has registered the
 * panels listed in the configuration.
 */
final class PanelRegisterTimeReplacer extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(PanelRegistry::class)->replace(AdminReplacementPanel::class);
    }
}

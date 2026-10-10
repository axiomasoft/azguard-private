<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Panels\PanelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A module that swaps the provider of the admin panel while the application boots.
 */
final class PanelReplacingProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(PanelRegistry::class)->replace(AdminReplacementPanel::class);
    }
}

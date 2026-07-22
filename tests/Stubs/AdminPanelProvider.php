<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelProvider;

/**
 * Minimal 'admin' panel used by the Filament integration tests. Its permission
 * catalog is supplied entirely by the Filament catalog builder (database
 * source), so no enums/roles are declared here.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin');
    }

    public function register(): void
    {
        AzGuard::registerPanel(fn (): Panel => $this->getPanel());
    }

    public function boot(): void {}
}

<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AzGuardFilamentServiceProvider extends ServiceProvider
{
    private const string PANEL_MIDDLEWARE = 'azguard.panel';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/azguard-filament.php', 'azguard-filament');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/azguard-filament.php' => config_path('azguard-filament.php')], 'azguard-filament-config');

        $this->app->booted($this->repeatPanelEntryOnLivewireUpdates(...));
    }

    /**
     * Livewire replays the middleware of the original route on an update when it is persistent, and it matches it by the
     * class that the route resolved the alias to, before the colon. The alias belongs to the core provider, which may boot
     * after this one, so the class is read once the application has booted.
     */
    private function repeatPanelEntryOnLivewireUpdates(): void
    {
        $class = $this->app->make(Router::class)->getMiddleware()[self::PANEL_MIDDLEWARE] ?? null;

        if (is_string($class)) {
            Livewire::addPersistentMiddleware($class);
        }
    }
}

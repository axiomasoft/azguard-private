<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Filament\Authorization\AuthorizesActions;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Authorization\RefusesEditableColumns;
use AzGuard\Filament\Commands\FilamentGenerateCommand;
use AzGuard\Filament\Exports\AuthorizesExports;
use Filament\Actions\Action;
use Filament\Actions\Events\ActionCalling;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'azguard-filament');
        $this->publishes([__DIR__.'/../config/azguard-filament.php' => config_path('azguard-filament.php')], 'azguard-filament-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FilamentGenerateCommand::class]);
        }

        Action::configureUsing(AuthorizesActions::configure(...));
        $this->app->make(Dispatcher::class)->listen(ActionCalling::class, AuthorizesActions::class);
        $this->app->make(Dispatcher::class)->listen(ActionCalling::class, AuthorizesExports::class);
        Livewire::listen('call', (new RefusesEditableColumns)(...));
        $this->app->booted($this->repeatPanelEntryOnLivewireUpdates(...));
        $this->app->booted($this->refuseUnownedFilamentChecks(...));
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

    /**
     * The hook comes after the Gate adapter of the core, which answers first for the permissions it owns.
     */
    private function refuseUnownedFilamentChecks(): void
    {
        $this->app->make(Gate::class)->before(static fn (?object $user, string $ability, array $arguments = []): ?bool => FilamentGate::before($user, $ability, $arguments));
    }
}

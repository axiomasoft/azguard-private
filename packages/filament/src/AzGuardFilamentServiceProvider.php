<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Attributes\Api;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Internal\Authorization\AuthorizesActions;
use AzGuard\Filament\Internal\Authorization\FilamentContext;
use AzGuard\Filament\Internal\Authorization\RefusesEditableColumns;
use AzGuard\Filament\Internal\Authorization\RefusesSurfaceCalls;
use AzGuard\Filament\Internal\Authorization\RefusesWidgetBoot;
use AzGuard\Filament\Internal\Console\FilamentGenerateCommand;
use AzGuard\Filament\Internal\Exports\AuthorizesExports;
use AzGuard\Filament\Internal\Exports\RefusesUnverifiableDownload;
use Filament\Actions\Action;
use Filament\Actions\Events\ActionCalling;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

#[Api(since: '1.0')]
final class AzGuardFilamentServiceProvider extends ServiceProvider
{
    private const string PANEL_MIDDLEWARE = 'azguard.panel';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/azguard-filament.php', 'azguard-filament');
        $this->app->bindIf(DownloadExport::class, RefusesUnverifiableDownload::class);
        // Before Livewire registers its lifecycle hook, so a refused widget aborts prior to its own boot().
        // Applications that do not install Livewire never bind this alias.
        if ($this->app->bound('livewire')) {
            $this->app->make('livewire')->componentHook(RefusesWidgetBoot::class);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'azguard-filament');
        $this->publishes([__DIR__.'/../config/azguard-filament.php' => config_path('azguard-filament.php')], 'azguard-filament-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FilamentGenerateCommand::class]);
        }

        Action::configureUsing(AuthorizesActions::configure(...));
        Table::configureUsing(static function (Table $table): void {
            if (! $table->getLivewire() instanceof ManageRelatedRecords) {
                return;
            }
            $table->modifyQueryUsing(static function (Builder $query) use ($table): Builder {
                $resource = FilamentContext::resourceOf($table->getLivewire());

                return $resource === null ? $query : FilamentGate::visible($resource, $query);
            });
        });
        $this->app->make(Dispatcher::class)->listen(ActionCalling::class, AuthorizesActions::class);
        $this->app->make(Dispatcher::class)->listen(ActionCalling::class, AuthorizesExports::class);
        Livewire::listen('call', (new RefusesEditableColumns)(...));
        Livewire::listen('call', (new RefusesSurfaceCalls)(...));
        RefusesSurfaceCalls::guardWidgets();
        $this->app->make('events')->listen(ServingFilament::class, static function (): void {
            $panel = Filament::getCurrentPanel();

            if ($panel === null || ! $panel->hasTenancy()) {
                return;
            }

            foreach ($panel->getResources() as $resource) {
                $resource::registerTenancyModelGlobalScope($panel);
            }
        });
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

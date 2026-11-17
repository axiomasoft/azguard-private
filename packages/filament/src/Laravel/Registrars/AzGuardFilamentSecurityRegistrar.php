<?php

declare(strict_types=1);

namespace AzGuard\Filament\Laravel\Registrars;

use AzGuard\Filament\Internal\Authorization\AuthorizesActions;
use AzGuard\Filament\Internal\Authorization\FilamentContext;
use AzGuard\Filament\Internal\Authorization\RefusesEditableColumns;
use AzGuard\Filament\Internal\Authorization\RefusesSurfaceBoot;
use AzGuard\Filament\Internal\Authorization\RefusesSurfaceCalls;
use AzGuard\Filament\Internal\Exports\AuthorizesExports;
use Filament\Actions\Action;
use Filament\Actions\Events\ActionCalling;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Livewire\ComponentHookRegistry;
use Livewire\LivewireManager;
use Packman\Attributes\Internal;
use Packman\Filament\Security\Authorization\FilamentGate as PackmanFilamentGate;
use Packman\Foundation\Contracts\PackageRegistrar;
use WeakMap;

#[Internal('Registers Filament action, table, Livewire, export, and Gate::before security hooks on Packman Filament.')]
final class AzGuardFilamentSecurityRegistrar implements PackageRegistrar
{
    private const string PANEL_MIDDLEWARE = 'azguard.panel';

    public function register(Application $app): void
    {
        // Before Livewire registers its lifecycle hook, so a refused component aborts prior to its own boot().
        if (class_exists(ComponentHookRegistry::class)) {
            ComponentHookRegistry::register(RefusesSurfaceBoot::class);
        }
    }

    public function boot(Application $app): void
    {
        Action::configureUsing(AuthorizesActions::configure(...));
        Table::configureUsing(static function (Table $table): void {
            if (! $table->getLivewire() instanceof ManageRelatedRecords) {
                return;
            }
            $table->modifyQueryUsing(static function (Builder $query) use ($table): Builder {
                $resource = FilamentContext::resourceOf($table->getLivewire());

                return $resource === null ? $query : PackmanFilamentGate::visible($resource, $query);
            });
        });

        self::attachEventListeners($app->make(Dispatcher::class));

        if ($app->bound('livewire')) {
            self::attachLivewireHooks($app->make(LivewireManager::class));
        } else {
            $app->afterResolving('livewire', static function (mixed $livewire): void {
                if ($livewire instanceof LivewireManager) {
                    self::attachLivewireHooks($livewire);
                }
            });
        }

        $app->booted(static function () use ($app): void {
            if ($app->bound('livewire')) {
                self::attachLivewireHooks($app->make(LivewireManager::class));
            }
            self::repeatPanelEntryOnLivewireUpdates($app);
            // Delegates through Packman FilamentGate -> FilamentSurfaceGate -> AzGuardFilamentSurfaceGate.
            $app->make(Gate::class)->before(PackmanFilamentGate::before(...));
        });
    }

    private static function attachEventListeners(Dispatcher $events): void
    {
        /** @var WeakMap<Dispatcher, true>|null $attached */
        static $attached = null;
        $attached ??= new WeakMap;

        if (isset($attached[$events])) {
            return;
        }

        $events->listen(ActionCalling::class, AuthorizesActions::class);
        $events->listen(ActionCalling::class, AuthorizesExports::class);
        $events->listen(ServingFilament::class, static function (): void {
            $panel = Filament::getCurrentPanel();

            if ($panel === null || ! $panel->hasTenancy()) {
                return;
            }

            foreach ($panel->getResources() as $resource) {
                $resource::registerTenancyModelGlobalScope($panel);
            }
        });
        $attached[$events] = true;
    }

    private static function attachLivewireHooks(LivewireManager $livewire): void
    {
        /** @var WeakMap<LivewireManager, true>|null $attached */
        static $attached = null;
        $attached ??= new WeakMap;

        if (isset($attached[$livewire])) {
            return;
        }

        $attached[$livewire] = true;
        $livewire->listen('call', (new RefusesEditableColumns)(...));
        $livewire->listen('call', (new RefusesSurfaceCalls)(...));
        $livewire->listen('call', AuthorizesActions::beforeCall(...));
    }

    /**
     * Livewire replays the middleware of the original route on an update when it is persistent, matching by resolved
     * middleware class. The alias belongs to core, which may boot after this provider, so resolve after boot.
     */
    private static function repeatPanelEntryOnLivewireUpdates(Application $app): void
    {
        if (! $app->bound('livewire')) {
            return;
        }

        $class = $app->make(Router::class)->getMiddleware()[self::PANEL_MIDDLEWARE] ?? null;

        if (is_string($class)) {
            $app->make(LivewireManager::class)->addPersistentMiddleware($class);
        }
    }
}

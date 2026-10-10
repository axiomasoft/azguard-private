<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Catalog\CatalogCache;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Laravel\Console\Commands\CatalogCacheCommand;
use AzGuard\Laravel\Console\Commands\CatalogClearCommand;
use AzGuard\Laravel\Console\Commands\ExplainCommand;
use AzGuard\Laravel\Console\Commands\StorageMigrationCommand;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Storage\StorageTouched;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class AzGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/azguard.php', 'azguard');

        $this->app->singleton(
            AzGuardConfig::class,
            static fn (Application $app): AzGuardConfig => AzGuardConfig::fromRepository($app->make('config')),
        );

        $this->app->singleton(StorageRegistry::class, static fn (Application $app): StorageRegistry => new StorageRegistry(
            $app->make(AzGuardConfig::class), $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(CatalogCache::class, static fn (Application $app): CatalogCache => new CatalogCache(
            $app->make(AzGuardConfig::class)->catalogCachePath() ?? $app->bootstrapPath('cache/azguard.php'),
        ));

        $this->app->singleton(PanelRegistry::class, static fn (Application $app): PanelRegistry => new PanelRegistry(
            $app,
            new PanelCompiler(static fn (): array => $app->make(AzGuardConfig::class)->defaults()),
            static fn (): CatalogCache => $app->make(CatalogCache::class),
        ));
        $this->app->alias(PanelRegistry::class, PanelRegistryContract::class);

        $this->app->singleton(Authorizer::class);
        $this->app->scoped(PermissionSetCache::class);
        $this->app->make('events')->listen(StorageTouched::class, function (StorageTouched $event): void {
            foreach ($event->panels as $panel) {
                $this->app->make(PermissionSetCache::class)->forgetPanel($panel);
            }
        });

        $this->app->scoped(CurrentPanel::class);
        $this->app->scoped(CurrentContext::class);
        $this->app->scoped(WithinContext::class);

        $this->app->singleton(SourceManager::class, static fn (Application $app): SourceManager => new SourceManager($app));

        $this->app->singleton(AzGuardManager::class);
        $this->app->alias(AzGuardManager::class, 'azguard');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'azguard-migrations');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'azguard');

        $this->registerConfiguredPanels();

        if ($this->app->runningInConsole()) {
            $this->commands([CatalogCacheCommand::class, CatalogClearCommand::class, StorageMigrationCommand::class, ExplainCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->app->make(PanelRegistry::class)->freeze();
        });
    }

    /**
     * Providers of modules register themselves; the ones listed in the configuration are registered here.
     *
     * @throws InvalidConfigurationException
     */
    private function registerConfiguredPanels(): void
    {
        foreach ($this->app->make(AzGuardConfig::class)->panelProviders() as $provider) {
            if (! is_subclass_of($provider, PanelProvider::class)) {
                throw new InvalidConfigurationException(
                    'azguard.panels.providers lists '.$provider.', which is not a panel provider: it must extend '.PanelProvider::class.'.',
                );
            }

            $this->app->register($provider);
        }
    }
}

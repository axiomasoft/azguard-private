<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Attributes\Api;
use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Changes\Internal\ActingActor;
use AzGuard\Configuration\ConfigSections;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Context\Internal\CurrentContext;
use AzGuard\Context\Internal\WithinContext;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Events\Internal\EventObservers;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Laravel\Internal\About\AboutSection;
use AzGuard\Laravel\Internal\Console\Commands\CatalogCacheCommand;
use AzGuard\Laravel\Internal\Console\Commands\CatalogClearCommand;
use AzGuard\Laravel\Internal\Console\Commands\CatalogListCommand;
use AzGuard\Laravel\Internal\Console\Commands\DoctorCommand;
use AzGuard\Laravel\Internal\Console\Commands\ExplainCommand;
use AzGuard\Laravel\Internal\Console\Commands\GrantsListCommand;
use AzGuard\Laravel\Internal\Console\Commands\GrantsPruneCommand;
use AzGuard\Laravel\Internal\Console\Commands\PanelsListCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsCreateCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsDeleteCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsGrantCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsRevokeCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsShowCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesGrantCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesListCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesRenameKeyCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesRevokeCommand;
use AzGuard\Laravel\Internal\Console\Commands\SourcesListCommand;
use AzGuard\Laravel\Internal\Console\Commands\StateResetCommand;
use AzGuard\Laravel\Internal\Console\Commands\StorageMigrationCommand;
use AzGuard\Laravel\Internal\Http\Middleware\CheckPermission;
use AzGuard\Laravel\Internal\Http\Middleware\EnterPanel;
use AzGuard\Laravel\Internal\Queue\PanelContext;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\Internal\PanelCompiler;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Internal\StorageTouched;
use Illuminate\Auth\Access\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

#[Api(since: '0.7')]
final class AzGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/azguard.php', 'azguard');

        $this->app->singletonIf(ConfigSections::class);

        $this->app->singleton(
            AzGuardConfig::class,
            static fn (Application $app): AzGuardConfig => AzGuardConfig::fromRepository($app->make('config'), $app->make(ConfigSections::class)),
        );

        $this->app->singleton(StorageRegistry::class, static fn (Application $app): StorageRegistry => new StorageRegistry(
            $app->make(AzGuardConfig::class), $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(CatalogCache::class, static fn (Application $app): CatalogCache => new CatalogCache(
            $app->make(AzGuardConfig::class)->catalogCachePath() ?? $app->bootstrapPath('cache/azguard.php'),
        ));

        $this->app->singleton(PanelRegistry::class, static fn (Application $app): PanelRegistry => new PanelRegistry(
            $app,
            new PanelCompiler(
                static fn (): array => $app->make(AzGuardConfig::class)->defaults(),
                static fn (): array => [
                    'tenants' => $app->make(AzGuardConfig::class)->defaultTenantResolvers(),
                    'scopes' => $app->make(AzGuardConfig::class)->defaultScopeResolvers(),
                ],
            ),
            static fn (): CatalogCache => $app->make(CatalogCache::class),
        ));
        $this->app->alias(PanelRegistry::class, PanelRegistryContract::class);

        // Its stage graph reads the scoped CurrentContext and CurrentPanel, so it lives exactly as long as they do.
        $this->app->scoped(Authorizer::class);
        $this->app->scoped(PermissionSetCache::class);
        $this->app->make('events')->listen(StorageTouched::class, function (StorageTouched $event): void {
            foreach ($event->panels as $panel) {
                $this->app->make(PermissionSetCache::class)->forgetPanel($panel);
            }
        });

        $this->app->scoped(ActingActor::class);
        $this->app->scoped(CurrentPanel::class);
        $this->app->scoped(CurrentContext::class);
        $this->app->scoped(WithinContext::class);

        $this->app->singleton(PanelContext::class);
        $this->app->singleton(EventObservers::class);
        $this->app->singleton(SourceManager::class, static fn (Application $app): SourceManager => new SourceManager($app));

        $this->app->singleton(AzGuard::class);
        $this->app->alias(AzGuard::class, 'azguard');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'azguard-migrations');
        $this->publishes([__DIR__.'/../config/azguard.php' => config_path('azguard.php')], 'azguard-config');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'azguard');

        $this->registerConfiguredPanels();
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(EnterPanel::ALIAS, EnterPanel::class);
        $router->aliasMiddleware(CheckPermission::ALIAS, CheckPermission::class);

        if ($this->app->make(AzGuardConfig::class)->gateEnabled()) {
            $this->app->make(Gate::class)->before(fn (?object $user, string $ability, array $arguments): ?Response => $this->app->make(GateBridge::class)($user, $ability, $arguments));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CatalogCacheCommand::class, CatalogClearCommand::class, StorageMigrationCommand::class, ExplainCommand::class, DoctorCommand::class,
                PanelsListCommand::class, SourcesListCommand::class, CatalogListCommand::class, RolesListCommand::class, GrantsListCommand::class,
                PermissionsShowCommand::class, RolesGrantCommand::class, RolesRevokeCommand::class, PermissionsGrantCommand::class,
                PermissionsRevokeCommand::class, PermissionsCreateCommand::class, PermissionsDeleteCommand::class, RolesRenameKeyCommand::class,
                GrantsPruneCommand::class, StateResetCommand::class,
            ]);
        }
        $this->scheduleMaintenance();
        $this->registerLaravelMechanisms();

        $this->app->booted(function (): void {
            $this->app->make(PanelRegistry::class)->freeze();
        });
    }

    /**
     * What Laravel offers instead of a mechanism of the package: `optimize` and `optimize:clear` run the catalog
     * commands, `about` prints a section, and a queued job runs in the panel of the request that dispatched it.
     */
    private function registerLaravelMechanisms(): void
    {
        $this->registerOptimizations((new ReflectionClass(ServiceProvider::class))->hasMethod('optimizes'));

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add(AboutSection::TITLE, AboutSection::class);
        }

        $events = $this->app->make('events');
        // The context of the job is hydrated by a listener of the framework that boots before this one.
        $events->listen(JobProcessing::class, [PanelContext::class, 'entering']);
        $events->listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], [PanelContext::class, 'leaving']);
    }

    /**
     * `optimizes()` exists from Laravel 11.27.1; on an older 11.x `optimize` does not build the catalog cache and the
     * doctor warns about it for a production deployment.
     *
     * @param  bool  $supported  whether the framework offers `optimizes()`
     */
    private function registerOptimizations(bool $supported): void
    {
        if ($supported) {
            $this->optimizes(optimize: 'azguard:catalog:cache', clear: 'azguard:catalog:clear', key: 'azguard');
        }
    }

    /**
     * With `schedule.enabled` and a `schedule.prune_expired` frequency, expired grants of every panel are pruned by the
     * Laravel scheduler at that frequency: a scheduler method name such as `daily`, or a cron expression.
     */
    private function scheduleMaintenance(): void
    {
        $config = $this->app->make(AzGuardConfig::class);
        $frequency = $config->pruneExpiredFrequency();

        if (! $config->scheduleEnabled() || $frequency === null) {
            return;
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($frequency): void {
            $event = $schedule->command(GrantsPruneCommand::class);
            str_contains($frequency, ' ') ? $event->cron($frequency) : $event->{$frequency}();
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

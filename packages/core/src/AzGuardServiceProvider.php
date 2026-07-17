<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Auth\DirectGrantPolicy;
use AzGuard\Auth\PolicyAttributeRegistrar;
use AzGuard\Commands\AbilitiesCommand;
use AzGuard\Commands\CacheResetCommand;
use AzGuard\Commands\CatalogListCommand;
use AzGuard\Commands\CatalogValidateCommand;
use AzGuard\Commands\DoctorCommand;
use AzGuard\Commands\ExplainCommand;
use AzGuard\Commands\GrantCommand;
use AzGuard\Commands\GrantsListCommand;
use AzGuard\Commands\InstallCommand;
use AzGuard\Commands\ListPermissionsCommand;
use AzGuard\Commands\ListScopedRolesCommand;
use AzGuard\Commands\MakeGuardAbilitiesCommand;
use AzGuard\Commands\MakeGuardPanelCommand;
use AzGuard\Commands\MakeGuardPermissionCommand;
use AzGuard\Commands\MakeGuardPolicyCommand;
use AzGuard\Commands\MakeGuardRoleCommand;
use AzGuard\Commands\PruneGrantsCommand;
use AzGuard\Commands\RevokeGrantCommand;
use AzGuard\Commands\RoleAssignmentCommand;
use AzGuard\Commands\RolePermissionsCommand;
use AzGuard\Commands\SuperAdminCommand;
use AzGuard\Commands\SyncRolesCommand;
use AzGuard\Contracts\AbilitiesResolver;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\PermissionLayer;
use AzGuard\Contracts\PermissionMatcher;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;
use AzGuard\Events\RoleAttached;
use AzGuard\Events\RoleDetached;
use AzGuard\Guard\Authorizer;
use AzGuard\Guard\AzGuardDiagnostics;
use AzGuard\Http\Middleware\CheckAccess;
use AzGuard\Http\Middleware\CheckDirectGrant;
use AzGuard\Http\Middleware\LoadAzGuardRoles;
use AzGuard\Http\Middleware\PanelCheckAccess;
use AzGuard\Http\Middleware\SetCurrentPanel;
use AzGuard\Registry\Builders\CompositePermissionCatalog;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Sources\ClassRoleGrantSource;
use AzGuard\Registry\Sources\DatabaseRoleGrantSource;
use AzGuard\Registry\Sources\DirectGrantSource;
use AzGuard\Support\Config;
use AzGuard\Support\RequestState;
use AzGuard\Support\ScopedRoleCache;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

final class AzGuardServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__.'/../config/az-guard.php',
            key: 'az-guard',
        );

        // Config-swappable: 'az-guard.manager' picks the concrete class, resolved
        // lazily so an override set anywhere (config file, test env) is honoured.
        // The facade and the concrete AzGuardManager key both resolve THROUGH the
        // interface (via the alias below), so swapping this key reaches every
        // check(). The default is instantiated directly to keep the concrete key
        // aliased to the interface WITHOUT the interface->concrete->alias cycle.
        $this->app->singleton(AzGuardManagerInterface::class, static function ($app): AzGuardManagerInterface {
            $class = Config::managerClass();

            return $class === AzGuardManager::class
                ? new AzGuardManager
                : $app->make($class);
        });
        $this->app->alias(AzGuardManagerInterface::class, AzGuardManager::class);

        $this->app->singleton(PolicyAttributeRegistrar::class);

        $this->app->singleton(AzGuardDiagnostics::class);

        // ─── Registry ─────────────────────────────────────────────────────────

        $this->app->singleton(ClassRoleGrantSource::class);
        $this->app->singleton(DatabaseRoleGrantSource::class);
        $this->app->singleton(DirectGrantSource::class);

        $this->app->tag([
            ClassRoleGrantSource::class,
            DatabaseRoleGrantSource::class,
            DirectGrantSource::class,
        ], AzGuardManager::GRANT_SOURCES_TAG);

        // Scoped, not singleton: PermissionCache holds per-request resolved
        // permission sets. Under Octane a singleton would bleed one user's
        // permissions into the next request on the same worker.
        $this->app->scoped(PermissionCache::class);

        // Reset per request (Octane-safe) — caches scoped-role rows for HasScopedRoles.
        $this->app->scoped(ScopedRoleCache::class);

        // Per-request scratch state (Octane-safe once-per-request side effects).
        $this->app->scoped(RequestState::class);

        // Scoped as well: the resolver captures the PermissionCache instance at
        // construction, so it must share the cache's per-request lifecycle.
        $this->app->scoped(EffectivePermissionResolver::class, function (): EffectivePermissionResolver {
            $allSources = iterator_to_array($this->app->tagged(AzGuardManager::GRANT_SOURCES_TAG), preserve_keys: false);
            $allowlist = Config::grantSources();

            $sources = $allowlist !== null
                ? array_filter($allSources, static fn (object $s): bool => in_array($s::class, $allowlist, strict: true))
                : $allSources;

            return new EffectivePermissionResolver(
                catalog: $this->app->make(PermissionCatalog::class),
                sources: $sources,
                cache: $this->app->make(PermissionCache::class),
                layer: $this->app->bound(PermissionLayer::class)
                    ? $this->app->make(PermissionLayer::class)
                    : null,
            );
        });

        // Config-swappable: 'az-guard.resolver' picks the concrete class bound
        // to the interface, reaching every check(). The scoped factory above
        // stays the resolution path for the default EffectivePermissionResolver
        // class itself, preserving its per-request PermissionCache lifecycle;
        // a custom resolver class is built normally by the container (it is
        // responsible for its own lifecycle if it needs one).
        $this->app->bind(PermissionResolverInterface::class, static fn ($app): PermissionResolverInterface => $app->make(Config::resolverClass()));

        // Config-swappable wildcard grammar. Singleton so compiled patterns are
        // memoized for the whole process; the class is a pure pattern->regex
        // function, safe to share across requests/users.
        $this->app->singleton(PermissionMatcher::class, static fn ($app): PermissionMatcher => $app->make(Config::matcherClass()));

        // Config-swappable curated frontend ability projection (AzGuard::abilitiesFor).
        $this->app->bind(AbilitiesResolver::class, static fn ($app): AbilitiesResolver => $app->make(Config::abilitiesResolverClass()));

        // Config-swappable opt-in role-permission validator (default lenient).
        $this->app->bind(RolePermissionValidator::class, static fn ($app): RolePermissionValidator => $app->make(Config::rolePermissionValidatorClass()));
    }

    public function boot(): void
    {
        // Fail fast on a misconfigured morph type before any migration or
        // polymorphic query can silently build the wrong column type.
        Config::morphType();

        $this->registerPanelProviders();

        $this->loadMigrationsFrom(paths: __DIR__.'/../database/migrations');

        $this->app->singleton(PermissionCatalog::class, function (): PermissionCatalog {
            $manager = $this->app->make(AzGuardManagerInterface::class);

            $builders = iterator_to_array(
                $this->app->tagged(AzGuardManager::CATALOG_BUILDERS_TAG),
                preserve_keys: false,
            );

            return new CompositePermissionCatalog(
                builders: $builders,
                // Lazy: a panel registered after boot is visible via panels().
                panelIds: static fn (): array => array_keys($manager->getPanels()),
            );
        });

        // Use instanceof instead of method_exists for a precise type check.
        Gate::before(function ($user, string $ability): ?bool {
            if (! $user instanceof Authenticatable) {
                return null;
            }

            return app(Authorizer::class)->check(user: $user, ability: $ability);
        });

        Gate::define('direct-grant', [DirectGrantPolicy::class, 'check']);

        // AzGuardManager is a singleton (it holds boot-time panel registrations),
        // but currentPanel is per-request state. Reset it between Octane requests
        // so a stale panel from a previous request cannot leak. No-op without Octane.
        Event::listen('Laravel\Octane\Events\RequestReceived', function (): void {
            if ($this->app->resolved(AzGuardManagerInterface::class)) {
                $this->app->make(AzGuardManagerInterface::class)->setCurrentPanel(null);
            }
        });

        $this->registerCacheInvalidation();

        if (Config::pruneExpiredDaily()) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('guard:prune-grants')->daily();
            });
        }

        $this->registerMiddlewareAliases();
        $this->registerBladeDirectives();
        $this->registerAbout();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/az-guard.php' => config_path('az-guard.php'),
            ], 'az-guard-config');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                CatalogListCommand::class,
                CatalogValidateCommand::class,
                RolePermissionsCommand::class,
                RoleAssignmentCommand::class,
                MakeGuardPanelCommand::class,
                MakeGuardPermissionCommand::class,
                MakeGuardPolicyCommand::class,
                MakeGuardAbilitiesCommand::class,
                MakeGuardRoleCommand::class,
                ListPermissionsCommand::class,
                ListScopedRolesCommand::class,
                GrantsListCommand::class,
                SyncRolesCommand::class,
                CacheResetCommand::class,
                GrantCommand::class,
                RevokeGrantCommand::class,
                PruneGrantsCommand::class,
                SuperAdminCommand::class,
                ExplainCommand::class,
                AbilitiesCommand::class,
            ]);
        }
    }

    /**
     * Surface AzGuard's state in `php artisan about` — version, registered
     * panels, default panel, cache store and the direct-grants feature flag.
     */
    protected function registerAbout(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('AzGuard', static function (): array {
            $manager = app(AzGuardManagerInterface::class);
            $panels = array_keys($manager->getPanels());

            $version = class_exists(InstalledVersions::class)
                ? (InstalledVersions::getPrettyVersion('axioma-studio/azguard-core') ?? 'dev-main')
                : 'dev-main';

            return [
                'Version' => $version,
                'Panels' => $panels === [] ? '—' : implode(', ', $panels),
                'Default Panel' => Config::defaultPanel() ?? '—',
                'Cache Store' => (string) config('az-guard.cache.store', 'array'),
                'Direct Grants' => (bool) config('az-guard.features.direct_grants', true) ? 'enabled' : 'disabled',
            ];
        });
    }

    /**
     * Flush the permission cache whenever grants or roles change through ANY
     * path — the fluent GrantBuilder, the AzGuard facade, console commands, or
     * any code that dispatches these events. The model-trait helpers also flush
     * inline; these listeners cover the paths that previously did not (notably
     * GrantBuilder, which only fired the events). Without this a revoked grant
     * could stay live until TTL when a persistent cache store is used.
     */
    protected function registerCacheInvalidation(): void
    {
        Event::listen(
            [GrantGiven::class, GrantRevoked::class],
            static function (GrantGiven|GrantRevoked $event): void {
                app(PermissionResolverInterface::class)->forgetForUser($event->user, $event->panelId);
            },
        );

        Event::listen(
            [RoleAttached::class, RoleDetached::class],
            static function (RoleAttached|RoleDetached $event): void {
                if (method_exists($event->model, 'flushPermissions')) {
                    $event->model->flushPermissions();
                }
            },
        );
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app->make(Router::class);

        if (! $router instanceof Router) {
            return;
        }

        $router->aliasMiddleware('azguard.roles', LoadAzGuardRoles::class);
        $router->aliasMiddleware('azguard.panel', SetCurrentPanel::class);
        $router->aliasMiddleware('azguard.check', CheckAccess::class);
        $router->aliasMiddleware('azguard.grant', CheckDirectGrant::class);
        $router->aliasMiddleware('azguard.panel_check', PanelCheckAccess::class);

        $alias = Config::checkAccessAlias();

        if ($alias !== 'azguard.check') {
            $router->aliasMiddleware($alias, CheckAccess::class);
        }
    }

    /**
     * Blade directives.
     *
     * @azcan    / @endazcan    — permission check
     *
     * @elseazcan / @unlessazcan / @endunlessazcan — added in DX2
     *
     * @azrole   / @endazrole   — role check
     *
     * @azdirect / @endazdirect — direct grant check
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('azcan', fn (string $expression): string => "<?php if (\\AzGuard\\Support\\BladeHelper::authed() && auth()->user()->hasPermission({$expression})): ?>");

        Blade::directive('endazcan', fn (): string => '<?php endif; ?>');

        Blade::directive('elseazcan', fn (string $expression): string => "<?php elseif (\\AzGuard\\Support\\BladeHelper::authed() && auth()->user()->hasPermission({$expression})): ?>");

        Blade::directive('unlessazcan', fn (string $expression): string => "<?php if (! \\AzGuard\\Support\\BladeHelper::authed() || ! auth()->user()->hasPermission({$expression})): ?>");

        Blade::directive('endunlessazcan', fn (): string => '<?php endif; ?>');

        Blade::directive('azrole', fn (string $expression): string => "<?php if (\\AzGuard\\Support\\BladeHelper::authed() && auth()->user()->hasRole({$expression})): ?>");

        Blade::directive('endazrole', fn (): string => '<?php endif; ?>');

        Blade::directive('azdirect', fn (string $expression): string => "<?php if (\\AzGuard\\Support\\BladeHelper::authed() && method_exists(auth()->user(), 'hasGrant') && auth()->user()->hasGrant({$expression})): ?>");

        Blade::directive('endazdirect', fn (): string => '<?php endif; ?>');
    }

    protected function registerPanelProviders(): void
    {
        foreach (Config::panels() as $provider) {
            if (is_string($provider) && class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }
}

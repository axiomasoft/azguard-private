<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Context\Commands\ContextGrantCommand;
use AzGuard\Context\Commands\ContextRevokeCommand;
use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Context\Contracts\ResolvesContext;
use AzGuard\Context\Events\ContextGrantGiven;
use AzGuard\Context\Events\ContextGrantRevoked;
use AzGuard\Context\Middleware\SetAuthorizationContext;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Contracts\ContextGuard as ContextGuardContract;
use AzGuard\Contracts\PermissionLayer;
use AzGuard\Contracts\PermissionResolverInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Service Provider for azguard/context.
 *
 * Publishes the config, registers the AuthorizationContextManager singleton,
 * binds the default strategy, registers the middleware.
 *
 * Config az-guard-context.php:
 *   'merge_strategy' => GlobalPlusContextStrategy::class,
 *   'resolvers'      => [],   // FQCNs of ResolvesContext classes
 */
final class AzGuardContextServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/az-guard-context.php',
            'az-guard-context',
        );

        // Scoped — lives for a single request. As a singleton under Octane it would
        // leak one tenant's context into the next request on the same worker.
        $this->app->scoped(AuthorizationContextManager::class);

        // Strategy — configured in az-guard-context.php
        $this->app->bind(MergeStrategy::class, function (Application $app): MergeStrategy {
            $class = config('az-guard-context.merge_strategy', GlobalPlusContextStrategy::class);

            return $app->make($class);
        });

        // Resolvers from config — tagged for injection into the middleware
        $resolvers = config('az-guard-context.resolvers', []);
        foreach ($resolvers as $resolverClass) {
            $this->app->tag($resolverClass, ResolvesContext::class);
        }

        $this->app->bind(SetAuthorizationContext::class, fn (Application $app): SetAuthorizationContext => new SetAuthorizationContext(
            manager: $app->make(AuthorizationContextManager::class),
            resolvers: $app->tagged(ResolvesContext::class),
        ));

        // ContextPermissionLayer — scoped: applies the merge strategy to the global
        // permission set AFTER all sources are aggregated in EffectivePermissionResolver,
        // so ContextOnly/DenyWithoutContext can restrict, not only add.
        // Holds the scoped manager — shares its per-request lifecycle.
        $this->app->scoped(PermissionLayer::class, fn (Application $app): ContextPermissionLayer => new ContextPermissionLayer(
            manager: $app->make(AuthorizationContextManager::class),
            strategy: $app->make(MergeStrategy::class),
        ));

        // ContextGuard — implementation of the core contract for one-off context checks
        // ($user->hasPermissionIn(...) / hasPermission(..., $context)).
        // Scoped — injects the scoped manager and resolver, must share their instances.
        $this->app->scoped(ContextGuardContract::class, ContextGuard::class);
    }

    public function boot(): void
    {
        $this->registerMiddlewareAlias();
        $this->registerCacheInvalidation();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/az-guard-context.php' => config_path('az-guard-context.php'),
            ], 'azguard-context-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'azguard-context-migrations');

            $this->commands([
                ContextGrantCommand::class,
                ContextRevokeCommand::class,
            ]);
        }
    }

    /**
     * Flush the permission cache whenever a context grant is written or revoked
     * through ANY path — the fluent ContextGrantBuilder, the console commands,
     * or any code that dispatches these events. ContextRole::booted() covers
     * only single-row model events; revoke()/revokeAll() use a mass-delete that
     * fires no model event, so without this listener a revoked context grant
     * would stay live until TTL on a persistent cache store. Mirrors core's
     * AzGuardServiceProvider::registerCacheInvalidation().
     */
    private function registerCacheInvalidation(): void
    {
        Event::listen(
            [ContextGrantGiven::class, ContextGrantRevoked::class],
            static function (ContextGrantGiven|ContextGrantRevoked $event): void {
                app(PermissionResolverInterface::class)->forgetForUser($event->user, $event->panelId);
            },
        );
    }

    /**
     * Auto-register the 'azguard.context' middleware alias, so a route can
     * do ->middleware('azguard.context') without the host app hand-wiring
     * SetAuthorizationContext in bootstrap/app.php — the previous silent
     * trap (F14): the alias existed only in a docblock example.
     */
    private function registerMiddlewareAlias(): void
    {
        $router = $this->app->make(Router::class);

        if (! $router instanceof Router) {
            return;
        }

        $router->aliasMiddleware('azguard.context', SetAuthorizationContext::class);
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Registrars;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\AzGuard;
use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Changes\Internal\ActingActor;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Context\Internal\CurrentContext;
use AzGuard\Context\Internal\WithinContext;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Events\Internal\EventObservers;
use AzGuard\Laravel\Internal\Queue\PanelContext;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\Internal\PanelCompiler;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\Internal\StorageTouched;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal('Package runtime bindings, caches, and facade root registrar.')]
final class AzGuardRuntimeRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->singleton(PanelRegistry::class, static fn (Application $app): PanelRegistry => new PanelRegistry(
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
        $app->alias(PanelRegistry::class, PanelRegistryContract::class);

        // Its stage graph reads the scoped CurrentContext and CurrentPanel, so it lives exactly as long as they do.
        $app->scoped(Authorizer::class);
        $app->scoped(PermissionSetCache::class);
        $app->make('events')->listen(StorageTouched::class, static function (StorageTouched $event) use ($app): void {
            foreach ($event->panels as $panel) {
                $app->make(PermissionSetCache::class)->forgetPanel($panel);
            }
        });

        $app->scoped(ActingActor::class);
        $app->scoped(CurrentPanel::class);
        $app->scoped(CurrentContext::class);
        $app->scoped(WithinContext::class);

        $app->singleton(PanelContext::class);
        // Before the framework hydrates a job, so the request hint can be restored when the job ends.
        $app->make('events')->listen(JobProcessing::class, [PanelContext::class, 'snapshot']);
        $app->singleton(EventObservers::class);
        $app->singleton(SourceManager::class, static fn (Application $app): SourceManager => new SourceManager($app));

        $app->singleton(AzGuard::class);
        $app->alias(AzGuard::class, 'azguard');
    }

    public function boot(Application $app): void {}
}

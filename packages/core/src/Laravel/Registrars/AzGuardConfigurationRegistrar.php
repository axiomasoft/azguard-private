<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Registrars;

use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Configuration\ConfigSections;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Storage\Internal\StorageRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;

#[Internal('Package configuration, storage registry, and catalog cache registrar.')]
final class AzGuardConfigurationRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->register(PackmanServiceProvider::class);

        $app->singletonIf(
            ConfigSections::class,
            static fn (Application $app): ConfigSections => new ConfigSections($app),
        );

        $app->singleton(
            AzGuardOptions::class,
            static fn (Application $app): AzGuardOptions => AzGuardOptions::fromRepository(
                $app->make('config'),
                $app->make(ConfigSections::class),
            ),
        );

        $app->singleton(
            AzGuardConfig::class,
            static fn (Application $app): AzGuardConfig => AzGuardConfig::fromRepository(
                $app->make('config'),
                $app->make(ConfigSections::class),
            ),
        );

        $app->singleton(
            StorageRegistry::class,
            static fn (Application $app): StorageRegistry => new StorageRegistry(
                $app->make(AzGuardConfig::class),
                $app->make(DatabaseManager::class),
            ),
        );

        $app->singleton(
            CatalogCache::class,
            static fn (Application $app): CatalogCache => new CatalogCache(
                $app->make(AzGuardConfig::class)->catalogCachePath() ?? $app->bootstrapPath('cache/azguard.php'),
            ),
        );
    }

    public function boot(Application $app): void {}
}

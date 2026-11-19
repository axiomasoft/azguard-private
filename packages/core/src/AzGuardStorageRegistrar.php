<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Internal\StorageTouched;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class AzGuardStorageRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    public function register(Application $app): void
    {
        $app->singleton(
            StorageRegistry::class,
            static fn (Application $app): StorageRegistry => new StorageRegistry(
                $app->make(AzGuardConfig::class),
                $app->make(DatabaseManager::class),
            ),
        );

        $app->make('events')->listen(StorageTouched::class, static function (StorageTouched $event) use ($app): void {
            foreach ($event->panels as $panel) {
                $app->make(PermissionSetCache::class)->forgetPanel($panel);
            }
        });
    }
}

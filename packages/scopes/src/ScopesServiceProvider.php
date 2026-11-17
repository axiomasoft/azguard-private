<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Scopes\Laravel\Registrars\ScopesExtensionRegistrar;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;

/**
 * Composition root of `axiomasoft/azguard-scopes`.
 */
#[Api(since: '1.0')]
final class ScopesServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ScopesExtensionRegistrar::class,
    ];

    public function register(): void
    {
        $this->app->register(PackmanServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->registerPackageRegistrars();
    }
}

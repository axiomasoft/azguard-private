<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Filament\Internal\Console\FilamentGenerateCommand;
use AzGuard\Filament\Laravel\Registrars\AzGuardFilamentConfigurationRegistrar;
use AzGuard\Filament\Laravel\Registrars\AzGuardFilamentExtensionRegistrar;
use AzGuard\Filament\Laravel\Registrars\AzGuardFilamentSecurityRegistrar;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Filament\PackmanFilamentServiceProvider;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;

#[Api(since: '1.0')]
final class AzGuardFilamentServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        AzGuardFilamentExtensionRegistrar::class,
        AzGuardFilamentConfigurationRegistrar::class,
        AzGuardFilamentSecurityRegistrar::class,
    ];

    public function register(): void
    {
        $this->app->register(PackmanServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->app->register(PackmanFilamentServiceProvider::class);
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'azguard-filament');
        $this->publishes([__DIR__.'/../config/azguard-filament.php' => config_path('azguard-filament.php')], 'azguard-filament-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FilamentGenerateCommand::class]);
        }

        $this->bootPackageRegistrars();
    }
}

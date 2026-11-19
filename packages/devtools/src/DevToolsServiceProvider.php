<?php

declare(strict_types=1);

namespace AzGuard\DevTools;

use AzGuard\DevTools\Internal\Console\InstallCommand;
use AzGuard\DevTools\Internal\Console\Make\MakeModelsCommand;
use AzGuard\DevTools\Internal\Console\Make\MakePanelCommand;
use AzGuard\DevTools\Internal\Console\Make\MakePermissionCommand;
use AzGuard\DevTools\Internal\Console\Make\MakePipeCommand;
use AzGuard\DevTools\Internal\Console\Make\MakePluginCommand;
use AzGuard\DevTools\Internal\Console\Make\MakePolicyCommand;
use AzGuard\DevTools\Internal\Console\Make\MakeRestrictionCommand;
use AzGuard\DevTools\Internal\Console\Make\MakeRoleCommand;
use AzGuard\DevTools\Internal\Console\Make\MakeSourceCommand;
use AzGuard\DevTools\Internal\Console\StubsCommand;
use AzGuard\DevTools\Laravel\Registrars\DevToolsExtensionRegistrar;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;

/**
 * The composition root of the DevTools package: it owns the `scaffold` section of `config/azguard.php` and, only when
 * the application runs in the console, registers `azguard:install`, `azguard:stubs` and the `azguard:make:*`
 * generators. Outside the console it resolves nothing but `ConfigSections`.
 */
#[Api(since: '1.0')]
final class DevToolsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        DevToolsExtensionRegistrar::class,
    ];

    public function register(): void
    {
        $this->app->register(PackmanServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        if ($this->app->make(PluginInspector::class)->facetsOf('axiomasoft.azguard-devtools') === null) {
            return;
        }

        $this->bootPackageRegistrars();

        if ($this->app->runningInConsole()) {
            $this->app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    InstallCommand::class, StubsCommand::class, MakePanelCommand::class, MakePermissionCommand::class, MakeRoleCommand::class,
                    MakePolicyCommand::class, MakeSourceCommand::class, MakePluginCommand::class, MakeRestrictionCommand::class, MakePipeCommand::class,
                    MakeModelsCommand::class,
                ]);
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\DevTools;

use AzGuard\Attributes\Api;
use AzGuard\Configuration\ConfigSections;
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
use AzGuard\DevTools\Internal\ScaffoldConfig;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root of the DevTools module: it owns the `scaffold` section of `config/azguard.php` and, only when
 * the application runs in the console, registers `azguard:install`, `azguard:stubs` and the `azguard:make:*`
 * generators. Outside the console it resolves nothing but `ConfigSections`.
 */
#[Api(since: '1.0')]
final class DevToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(ConfigSections::class);
        $this->app->make(ConfigSections::class)->own('scaffold', ScaffoldConfig::validate(...));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class, StubsCommand::class, MakePanelCommand::class, MakePermissionCommand::class, MakeRoleCommand::class,
                MakePolicyCommand::class, MakeSourceCommand::class, MakePluginCommand::class, MakeRestrictionCommand::class, MakePipeCommand::class,
                MakeModelsCommand::class,
            ]);
        }
    }
}

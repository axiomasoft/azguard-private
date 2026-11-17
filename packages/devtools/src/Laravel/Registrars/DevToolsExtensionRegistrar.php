<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Laravel\Registrars;

use AzGuard\Configuration\ConfigSections;
use AzGuard\DevTools\Extensions\DevToolsExtensionPlugin;
use AzGuard\DevTools\Internal\ScaffoldConfig;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;

#[Internal('Registers AzGuard DevTools scaffold configuration section and Packman extension metadata.')]
final class DevToolsExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->singletonIf(
            ConfigSections::class,
            static fn (Application $app): ConfigSections => new ConfigSections($app),
        );
        $app->make(ConfigSections::class)->own('scaffold', ScaffoldConfig::validate(...));

        ExtensionFacetRegistrar::register(
            $app,
            new DevToolsExtensionPlugin,
            dirname(__DIR__, 3).'/composer.json',
        );

        if (! $app->resolved(PluginInspector::class)) {
            ExtensionRegistrar::builtIn(DevToolsExtensionPlugin::class);
        }
    }

    public function boot(Application $app): void {}
}

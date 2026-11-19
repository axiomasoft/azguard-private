<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Registrars;

use AzGuard\Extensions\AzGuardCorePlugin;
use AzGuard\Panels\Internal\PanelRegistry;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Panels\Contracts\Registry\PanelRegistry as PackmanPanelRegistry;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;

#[Internal('Packman extension, schema, diagnostics, and panel facets registrar.')]
final class AzGuardExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->register(PluginsServiceProvider::class);
        $app->register(SchemaServiceProvider::class);

        if (! $app->bound(PackmanPanelRegistry::class)) {
            $app->register(PanelsServiceProvider::class);
        }

        if (! $app->resolved(PluginInspector::class)) {
            ExtensionRegistrar::builtInFor($app, AzGuardCorePlugin::class);
        }
    }

    public function boot(Application $app): void
    {
        $app->booted(static function () use ($app): void {
            $app->make(PanelRegistry::class)->freeze();
        });
    }
}

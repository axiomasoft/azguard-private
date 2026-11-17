<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Registrars;

use AzGuard\Extensions\AzGuardCorePlugin;
use AzGuard\Panels\Internal\PanelRegistry;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Panels\Contracts\Registry\PanelRegistry as PackmanPanelRegistry;
use Packman\Panels\Laravel\PanelExtensionBridge;
use Packman\Panels\Laravel\PanelsBindingsRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;

#[Internal('Packman extension, schema, diagnostics, and panel facets registrar.')]
final class AzGuardExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $plugin = new AzGuardCorePlugin;
        $manifest = dirname(__DIR__, 3).'/composer.json';

        if (! $app->bound(PackmanPanelRegistry::class)) {
            (new PanelsBindingsRegistrar)->register($app);
        }

        ExtensionFacetRegistrar::register($app, $plugin, $manifest);
        SchemaExtensions::register($app, $plugin, 'azguard');
        PanelExtensionBridge::registerExtension($app, $plugin);
    }

    public function boot(Application $app): void
    {
        $app->booted(static function () use ($app): void {
            $app->make(PanelRegistry::class)->freeze();
        });
    }
}

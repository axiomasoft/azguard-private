<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Laravel\Registrars;

use AzGuard\Scopes\Extensions\ScopesExtensionPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;

#[Internal('Registers AzGuard Scopes Packman extension facets and diagnostics.')]
final class ScopesExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        ExtensionFacetRegistrar::register(
            $app,
            new ScopesExtensionPlugin,
            dirname(__DIR__, 3).'/composer.json',
        );

        if (! $app->resolved(PluginInspector::class)) {
            ExtensionRegistrar::builtInFor($app, ScopesExtensionPlugin::class);
        }
    }

    public function boot(Application $app): void {}
}

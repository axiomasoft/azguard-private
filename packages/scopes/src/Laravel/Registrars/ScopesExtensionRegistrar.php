<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Laravel\Registrars;

use AzGuard\Scopes\Extensions\ScopesExtensionPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;

#[Internal('Registers AzGuard Scopes Packman extension facets and diagnostics.')]
final class ScopesExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        if (! $app->resolved(PluginInspector::class)) {
            ExtensionRegistrar::builtInFor($app, ScopesExtensionPlugin::class);
        }
    }

    public function boot(Application $app): void {}
}

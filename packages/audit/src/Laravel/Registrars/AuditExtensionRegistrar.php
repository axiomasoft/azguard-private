<?php

declare(strict_types=1);

namespace AzGuard\Audit\Laravel\Registrars;

use AzGuard\Audit\Extensions\AuditExtensionPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;

#[Internal('Registers AzGuard Audit Packman extension facets, schema sets, and diagnostics.')]
final class AuditExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        if (! $app->resolved(PluginInspector::class)) {
            ExtensionRegistrar::builtInFor($app, AuditExtensionPlugin::class);
        }
    }

    public function boot(Application $app): void {}
}

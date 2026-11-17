<?php

declare(strict_types=1);

namespace AzGuard\Audit\Laravel\Registrars;

use AzGuard\Audit\Extensions\AuditExtensionPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;

#[Internal('Registers AzGuard Audit Packman extension facets, schema sets, and diagnostics.')]
final class AuditExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $plugin = new AuditExtensionPlugin;

        ExtensionFacetRegistrar::register(
            $app,
            $plugin,
            dirname(__DIR__, 3).'/composer.json',
        );
        SchemaExtensions::register($app, $plugin, $plugin->manifest()->id);
    }

    public function boot(Application $app): void {}
}

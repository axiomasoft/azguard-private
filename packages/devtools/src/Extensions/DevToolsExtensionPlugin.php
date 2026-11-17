<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Extensions;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman extension plugin declaring AzGuard DevTools (dev-only scaffolding and installation tools).
 */
#[Api(since: '1.0')]
final class DevToolsExtensionPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard-devtools',
            version: '2.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql', 'mariadb'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}
}

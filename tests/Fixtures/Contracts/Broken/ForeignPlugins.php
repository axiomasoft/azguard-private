<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Plugins\Plugin;

/**
 * Plugins of a class outside the namespace `AzGuard\`, which the autoloader of the tests cannot load: the class is
 * declared on first use with the id the test asks for.
 */
final class ForeignPlugins
{
    private static int $declared = 0;

    public static function reservedId(string $id): Plugin
    {
        $class = 'Acme\\Foreign\\Plugin'.(++self::$declared);

        eval('namespace Acme\\Foreign; final class Plugin'.self::$declared.' implements \\'.Plugin::class.' {
            public function id(): string { return '.var_export($id, true).'; }
            public function register(\\AzGuard\\Panels\\PanelBuilder $panel, \\AzGuard\\Plugins\\PluginContext $context): void {}
        }');

        return new $class;
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/** A plugin whose id is a new one at every call. */
final class DriftingIdPlugin extends BasePlugin
{
    public static int $calls = 0;

    public function id(): string
    {
        return 'acme/drift-'.self::$calls++;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void {}
}

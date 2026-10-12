<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/** A plugin whose id is a new one at every call. */
final class DriftingIdPlugin implements Plugin
{
    public static int $calls = 0;

    public function id(): string
    {
        return 'acme/drift-'.self::$calls++;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void {}
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/** A plugin that requires another one. */
final class NeedsPlugin extends BasePlugin implements DependsOnPlugins
{
    public function id(): string
    {
        return 'acme/needs';
    }

    public function requires(): array
    {
        return ['acme/base'];
    }

    public function register(PanelBuilder $panel, PluginContext $context): void {}
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/** A plugin that adds a before hook to the panel it is attached to. */
class LabelPlugin extends BasePlugin
{
    public function id(): string
    {
        return 'acme/label';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->before([DenyEditHook::class]);
    }
}

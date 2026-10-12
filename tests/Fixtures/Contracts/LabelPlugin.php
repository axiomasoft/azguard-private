<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/** A plugin that adds a before hook to the panel it is attached to. */
class LabelPlugin implements Plugin
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

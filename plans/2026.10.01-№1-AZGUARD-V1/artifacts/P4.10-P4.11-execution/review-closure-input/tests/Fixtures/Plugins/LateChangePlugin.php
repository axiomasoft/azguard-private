<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin that keeps the builder and tries to change the panel when it boots.
 */
final class LateChangePlugin extends BasePlugin
{
    private ?PanelBuilder $builder = null;

    public function id(): string
    {
        return 'acme/late-change';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->builder = $panel;
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        $this->builder?->label('Changed after the boot');
    }
}

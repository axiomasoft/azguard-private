<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/** A plugin that registers another panel from its `boot()`. */
final class RegisteringBootPlugin extends BasePlugin
{
    public function id(): string
    {
        return 'acme/registering-boot';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void {}

    public function boot(Panel $panel, PluginContext $context): void
    {
        app(PanelRegistry::class)->register(self::class);
    }
}

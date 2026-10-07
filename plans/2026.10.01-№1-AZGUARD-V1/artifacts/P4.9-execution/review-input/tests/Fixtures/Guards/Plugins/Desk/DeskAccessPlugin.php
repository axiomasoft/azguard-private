<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Desk;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

final class DeskAccessPlugin extends BasePlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'desk/access';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->discover(__DIR__);
    }
}

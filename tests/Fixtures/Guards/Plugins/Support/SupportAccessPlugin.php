<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Support;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

final class SupportAccessPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'support/access';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->discover(__DIR__);
    }
}

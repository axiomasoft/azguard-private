<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

final class ChangingPlugin extends BasePlugin
{
    public function id(): string
    {
        return 'acme/changing';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->changing([new RecordingPipe('plugin')]);
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

final class ChangingPlugin implements Plugin
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

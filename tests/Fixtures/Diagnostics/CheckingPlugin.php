<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin that adds a doctor check to the panel it is attached to.
 */
final class CheckingPlugin extends BasePlugin
{
    public function id(): string
    {
        return 'acme/checking';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->doctorChecks([ProbeCheck::finding('acme.folders', 'Acme folders are missing.')]);
    }
}

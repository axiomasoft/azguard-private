<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin that adds a doctor check to the panel it is attached to.
 */
final class CheckingPlugin implements Plugin
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

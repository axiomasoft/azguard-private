<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use AzGuard\Tests\Fixtures\Contracts\DatabaseWriterPlugin;
use AzGuard\Tests\TestCase;

final class AuditPluginContractTest extends TestCase
{
    use PluginContractTests;

    protected function azguardPlugin(): Plugin
    {
        return AuditPlugin::make();
    }

    /** The journal participant needs the database writer on the panel. */
    protected function azguardCompanions(): array
    {
        return [new DatabaseWriterPlugin];
    }
}

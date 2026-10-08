<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use AzGuard\Tests\TestCase;

final class AuditPluginContractTest extends TestCase
{
    use PluginContractTests;

    protected function azguardPlugin(): Plugin
    {
        return AuditPlugin::make();
    }
}

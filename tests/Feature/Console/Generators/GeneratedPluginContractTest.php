<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Console\Generators;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use AzGuard\Tests\Fixtures\Console\GeneratesComponents;
use AzGuard\Tests\TestCase;

/** The plugin `azguard:make:plugin` writes passes the contract suite of plugins. */
final class GeneratedPluginContractTest extends TestCase
{
    use GeneratesComponents;
    use PluginContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate('azguard:make:plugin', ['name' => 'AuditTrail', '--panel' => 'Admin']);
    }

    protected function azguardPlugin(): Plugin
    {
        $class = $this->generatedClass('Admin\Plugins\AuditTrailPlugin');

        return $class::make();
    }
}

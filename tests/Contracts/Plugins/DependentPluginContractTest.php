<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use AzGuard\Tests\Fixtures\Contracts\DependentPlugin;
use AzGuard\Tests\Fixtures\Contracts\LabelPlugin;
use AzGuard\Tests\TestCase;

final class DependentPluginContractTest extends TestCase
{
    use PluginContractTests;

    protected function azguardPlugin(): Plugin
    {
        return new DependentPlugin;
    }

    protected function azguardCompanions(): array
    {
        return [new LabelPlugin];
    }
}

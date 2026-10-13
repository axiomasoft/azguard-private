<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Consumer;

use Acme\Consumer\ConsumerPlugin;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

require_once dirname(__DIR__, 2).'/Fixtures/Modules/Consumer/autoload.php';

/** I8: the plugin of a package outside `AzGuard\` passes the plugin suite the built-in modules pass. */
final class ConsumerPluginContractTest extends TestCase
{
    use DatabaseMigrations;
    use PluginContractTests;

    protected function azguardPlugin(): Plugin
    {
        return new ConsumerPlugin;
    }
}

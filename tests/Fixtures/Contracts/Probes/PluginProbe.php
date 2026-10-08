<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Testing\Contracts\PluginContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class PluginProbe
{
    use PluginContractTests;
    use RunsAsAProbe;

    /** @param Closure(): Plugin $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardPlugin(): Plugin
    {
        return ($this->make)();
    }
}
